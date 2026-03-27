<?php
/**
 * SND_P2000 – P2000 RSS fetcher + async geocoder.
 *
 * Geocoding flow:
 * 1. fetch()         → Snel. Parseert RSS, sla op. Stadscentrum direct uit tabel (geen HTTP).
 *                      Voegt item toe aan geo_queue als coord_type='city' (voor straat-verbetering).
 * 2. geocode_batch() → Async (cron 2min). Probeert straat+stad via Photon → Nominatim.
 *                      Bij succes: update naar straat-coördinaten, zet coord_type='street'.
 *                      Bij mislukking: behoud stadscentrum, verwijder uit queue.
 *
 * coord_type: 'city'   = stadscentrum (altijd beschikbaar, ~1-3km nauwkeurigheid)
 *             'street' = straat-geocoded (~50-200m nauwkeurigheid)
 *             'gps'    = directe GPS uit RSS feed (meest nauwkeurig)
 */
class SND_P2000 {

	const OPTION_FEEDS   = 'snd_p2000_feeds_structured';
	const OPTION_LOG     = 'snd_p2000_debug_log';
	const OPTION_GEO_Q   = 'snd_p2000_geo_queue';
	const MAX_PER_FEED   = 25;
	const GEO_BATCH_SIZE = 10;  // Google API can handle higher rate
	const GEO_MAX_TRIES  = 5;   // Stop retrying after this many failures
	const GEO_OK_TTL     = WEEK_IN_SECONDS;
	const GEO_FAIL_TTL   = 0;   // Never cache failures — always retry
	const GEO_TIMEOUT    = 5;

	private $log = array();

	// ── Built-in city coordinates (no HTTP needed) ────────────────────────────

	private static function city_coords() {
		return array(
			'tilburg'          => array( '51.5600', '5.0900' ),
			'breda'            => array( '51.5719', '4.7683' ),
			'eindhoven'        => array( '51.4416', '5.4697' ),
			'roosendaal'       => array( '51.5308', '4.4644' ),
			'bergen op zoom'   => array( '51.4964', '4.2878' ),
			'etten-leur'       => array( '51.5650', '4.6370' ),
			'oosterhout'       => array( '51.6414', '4.8669' ),
			'waalwijk'         => array( '51.6836', '5.0694' ),
			'dongen'           => array( '51.6257', '4.9369' ),
			'goirle'           => array( '51.5183', '5.0733' ),
			'oisterwijk'       => array( '51.5744', '5.1975' ),
			'rijen'            => array( '51.5858', '4.9233' ),
			'gilze'            => array( '51.5569', '4.9344' ),
			'kaatsheuvel'      => array( '51.6581', '5.0414' ),
			'loon op zand'     => array( '51.6367', '5.0739' ),
			'moergestel'       => array( '51.5481', '5.1733' ),
			'berkel-enschot'   => array( '51.5683', '5.1533' ),
			'berkel enschot'   => array( '51.5683', '5.1533' ),
			'udenhout'         => array( '51.6033', '5.1369' ),
			'sprang-capelle'   => array( '51.6619', '5.0244' ),
			'sprang capelle'   => array( '51.6619', '5.0244' ),
			'waspik'           => array( '51.6744', '5.0261' ),
			'drunen'           => array( '51.6853', '5.1358' ),
			'vlijmen'          => array( '51.6983', '5.2122' ),
			'heusden'          => array( '51.7269', '5.1397' ),
			'zundert'          => array( '51.4736', '4.6558' ),
			'riel'             => array( '51.5264', '5.0431' ),
			'hulten'           => array( '51.5744', '4.9447' ),
			'de moer'          => array( '51.5658', '5.0058' ),
			'oosteind'         => array( '51.6294', '4.8944' ),
			'\'s gravenmoer'   => array( '51.6608', '4.9533' ),
			's gravenmoer'     => array( '51.6608', '4.9533' ),
			'den bosch'        => array( '51.6978', '5.3037' ),
			'\'s-hertogenbosch'=> array( '51.6978', '5.3037' ),
			's-hertogenbosch'  => array( '51.6978', '5.3037' ),
			'hertogenbosch'    => array( '51.6978', '5.3037' ),
			'oss'              => array( '51.7647', '5.5194' ),
			'helmond'          => array( '51.4772', '5.6572' ),
			'boxtel'           => array( '51.5964', '5.3286' ),
			'vught'            => array( '51.6528', '5.2964' ),
			'haaren'           => array( '51.6008', '5.2044' ),
			'oudenbosch'       => array( '51.5908', '4.5258' ),
			'zevenbergen'      => array( '51.6444', '4.5983' ),
			'moerdijk'         => array( '51.6972', '4.6219' ),
			'hoeven'           => array( '51.5817', '4.5750' ),
			'steenbergen'      => array( '51.5928', '4.3161' ),
			'hoogerheide'      => array( '51.4233', '4.3122' ),
			'ossendrecht'      => array( '51.3994', '4.3408' ),
			'wouw'             => array( '51.5236', '4.4028' ),
			'baarle-nassau'    => array( '51.4458', '4.9328' ),
			'alphen'           => array( '51.4778', '4.9639' ),
			'rucphen'          => array( '51.5286', '4.5586' ),
			'sint willebrord'  => array( '51.5472', '4.5936' ),
			'klundert'         => array( '51.6683', '4.5261' ),
			'standdaarbuiten'  => array( '51.6583', '4.4911' ),
			'sprundel'         => array( '51.5214', '4.5350' ),
			'loon'             => array( '51.6367', '5.0739' ),
		);
	}

	private function city_centre( $city ) {
		$key = strtolower( trim( $city ) );
		// Remove NB suffix
		$key = preg_replace( '/\s+n\.?b\.?$/i', '', $key );
		$key = trim( $key );
		$map = self::city_coords();
		if ( isset( $map[ $key ] ) ) {
			return array( 'lat' => $map[ $key ][0], 'lon' => $map[ $key ][1] );
		}
		// Partial match
		foreach ( $map as $k => $v ) {
			if ( strpos( $key, $k ) !== false || strpos( $k, $key ) !== false ) {
				return array( 'lat' => $v[0], 'lon' => $v[1] );
			}
		}
		return null;
	}

	// ── Fast fetch ────────────────────────────────────────────────────────────

	public function fetch() {
		include_once ABSPATH . WPINC . '/feed.php';

		$this->log = array();
		$this->log( 'Fetch gestart: ' . wp_date( 'd-m-Y H:i:s' ) );

		$feed_urls = array(
			'brandweer' => get_option( 'snd_p2000_feed_brandweer', '' ),
			'politie'   => get_option( 'snd_p2000_feed_politie',   '' ),
			'mmt'       => get_option( 'snd_p2000_feed_mmt',       '' ),
		);

		$cities_raw   = trim( get_option( 'snd_p2000_cities', '' ) );
		$city_list    = array();
		if ( $cities_raw !== '' ) {
			foreach ( explode( ',', $cities_raw ) as $c ) {
				$c = trim( $c );
				if ( $c !== '' ) $city_list[] = $c;
			}
		}
		$primary_city = ! empty( $city_list ) ? strtolower( $city_list[0] ) : 'tilburg';

		$cities_regex = '';
		if ( ! empty( $city_list ) ) {
			$parts = array();
			foreach ( $city_list as $c ) {
				$parts[] = preg_quote( strtolower( $c ), '/' );
			}
			$cities_regex = implode( '|', $parts );
		}

		$this->log( $cities_regex ? 'Filter: ' . implode( ', ', $city_list ) : 'Geen steden-filter.' );

		$stored    = get_option( self::OPTION_FEEDS, array() );
		if ( ! is_array( $stored ) ) $stored = array();

		$geo_queue = get_option( self::OPTION_GEO_Q, array() );
		if ( ! is_array( $geo_queue ) ) $geo_queue = array();

		$new_count = 0;

		foreach ( $feed_urls as $type => $url ) {
			if ( empty( $url ) ) {
				$this->log( "{$type}: geen URL." );
				continue;
			}

			$this->log( "{$type}: ophalen…" );
			$feed = fetch_feed( $url );

			if ( is_wp_error( $feed ) ) {
				$this->log( "{$type}: FOUT – " . $feed->get_error_message() );
				$this->maybe_notify_admin( $type, $feed->get_error_message() );
				continue;
			}

			$this->log( "{$type}: " . $feed->get_item_quantity() . " items." );
			if ( ! isset( $stored[ $type ] ) ) $stored[ $type ] = array();

			foreach ( $feed->get_items() as $item ) {
				$msg_id  = $item->get_id( true );
				$title   = trim( (string) $item->get_title() );
				$desc    = wp_strip_all_tags( trim( (string) $item->get_description() ) );
				$raw     = strtolower( $type === 'mmt' ? $desc : $title );
				$display = $type === 'mmt' ? $desc : $title;

				// Detect actual service type from message content.
				// Brandweer messages always contain a BZB capcode (e.g. "bzb-01", "bzb-02").
				// MMT stays as-is (from the dedicated MMT feed).
				// Everything else from the politie/brandweer feed without BZB = politie.
				if ( $type === 'mmt' ) {
					$actual_type = 'mmt';
				} elseif ( preg_match( '/\bbzb\b/i', $raw ) ) {
					$actual_type = 'brandweer';
				} else {
					$actual_type = 'politie';
				}

				$existing    = isset( $stored[ $type ][ $msg_id ] ) ? $stored[ $type ][ $msg_id ] : null;
				$coord_type  = $existing['coord_type'] ?? '';

				// Already geocoded at street level or GPS → skip completely
				if ( $existing && in_array( $coord_type, array( 'street', 'gps' ), true ) ) {
					continue;
				}
				// 'pending' and 'failed' both get re-processed (retry geocoding)

				// City filter
				$stad = '';
				if ( $cities_regex !== '' ) {
					if ( ! preg_match( '/\b(' . $cities_regex . ')\b/i', $raw, $cm ) ) {
						continue;
					}
					$stad = ucwords( strtolower( $cm[1] ) );
				} else {
					$stad = ucwords( $this->extract_city( $raw ) );
				}
				$geocity = strtolower( $stad ) !== '' ? strtolower( $stad ) : $primary_city;

				// Parse street
				$straat = ( $existing && ! empty( $existing['straat'] ) )
					? $existing['straat']
					: $this->parse_street( $raw, $geocity );

				// RSS GPS (rare)
				$rss_lat = $item->get_latitude();
				$rss_lon = $item->get_longitude();

				if ( $rss_lat && $rss_lon ) {
					$record = $this->make_record(
						$msg_id, $item, $actual_type, $display, $stad, $straat,
						(string) $rss_lat, (string) $rss_lon, 'gps'
					);
					$stored[ $type ][ $msg_id ] = $record;
					unset( $geo_queue[ $msg_id ] );
					$this->log( "  {$msg_id}: GPS uit RSS." );

				} else {
					$stored[ $type ][ $msg_id ] = $this->make_record(
						$msg_id, $item, $actual_type, $display, $stad, $straat,
						null, null, 'pending'
					);

					$clean = $straat !== '' ? $this->clean_street_for_nominatim( $straat, $stad ) : '';
					if ( $clean !== '' ) {
						$geo_queue[ $msg_id ] = array(
							'type'  => $type,
							'clean' => $clean,
							'stad'  => $stad,
						);
						$this->log( "  {$msg_id}: [{$actual_type}] wachtrij '{$clean}, {$stad}'." );
					} else {
						unset( $geo_queue[ $msg_id ] );
						$this->log( "  {$msg_id}: [{$actual_type}] geen straat — geen marker." );
					}
				}

				if ( ! $existing ) $new_count++;
			}

			uasort( $stored[ $type ], array( $this, '_sort_desc' ) );
			$stored[ $type ] = array_slice( $stored[ $type ], 0, self::MAX_PER_FEED, true );

			$feed->__destruct();
			unset( $feed );
		}

		// Prune queue
		$all_ids = array();
		foreach ( $stored as $msgs ) {
			foreach ( array_keys( $msgs ) as $k ) $all_ids[] = $k;
		}
		foreach ( array_keys( $geo_queue ) as $qid ) {
			if ( ! in_array( $qid, $all_ids, true ) ) unset( $geo_queue[ $qid ] );
		}

		$this->log( "Klaar: {$new_count} nieuw, " . count( $geo_queue ) . " in wachtrij." );

		update_option( self::OPTION_FEEDS, $stored );
		update_option( self::OPTION_GEO_Q, $geo_queue );
		update_option( self::OPTION_LOG, array_slice( $this->log, 0, 200 ) );
	}

	public function _sort_desc( $a, $b ) {
		return intval( $b['tijd'] ?? 0 ) - intval( $a['tijd'] ?? 0 );
	}

	// ── Geocoding batch ───────────────────────────────────────────────────────

	public function geocode_batch() {
		$geo_queue = get_option( self::OPTION_GEO_Q, array() );
		if ( empty( $geo_queue ) ) return 0;

		$stored = get_option( self::OPTION_FEEDS, array() );
		$batch  = array_slice( $geo_queue, 0, self::GEO_BATCH_SIZE, true );
		$done   = 0;

		foreach ( $batch as $msg_id => $q ) {
			$type  = $q['type'];
			$clean = $q['clean'] ?? '';
			$stad  = $q['stad']  ?? '';

			unset( $geo_queue[ $msg_id ] );

			if ( ! isset( $stored[ $type ][ $msg_id ] ) ) continue;
			if ( $clean === '' ) continue;

			$coords = $this->geocode_street( $clean, $stad );

			if ( $coords ) {
				$stored[ $type ][ $msg_id ]['lat']        = $coords['lat'];
				$stored[ $type ][ $msg_id ]['lon']        = $coords['lon'];
				$stored[ $type ][ $msg_id ]['coord_type'] = 'street';
				$this->append_log( "OK '{$clean}, {$stad}' → {$coords['lat']},{$coords['lon']}" );
				$done++;
			} else {
				$stored[ $type ][ $msg_id ]['lat']        = null;
				$stored[ $type ][ $msg_id ]['lon']        = null;
				$stored[ $type ][ $msg_id ]['coord_type'] = 'failed';
				$this->append_log( "Niet gevonden: '{$clean}, {$stad}'" );
			}
		}

		update_option( self::OPTION_FEEDS, $stored );
		update_option( self::OPTION_GEO_Q, $geo_queue );

		return $done;
	}

	/**
	 * Try multiple query variants to maximise the chance of finding a street.
	 * Photon and Nominatim sometimes need different formats.
	 *
	 * Tries in order:
	 *  1. "Korvelseweg, Tilburg"           — exact
	 *  2. "Korvelseweg Tilburg"            — no comma (Photon prefers this)
	 *  3. "Ringbaanoost, Tilburg"          — strip hyphens (helps -oost/-west/-noord/-zuid)
	 *  4. "Ringbaan oost Tilburg"          — hyphen → space
	 *  5. "Korvelseweg, gemeente Tilburg"  — add gemeente prefix (helps smaller towns)
	 */
	private function geocode_street( $clean, $stad ) {
		// Build list of variants to try
		$variants = array();

		// 1. Standard: "Straat, Stad"
		$variants[] = $clean . ', ' . $stad;

		// 2. No comma (Photon sometimes prefers this)
		$variants[] = $clean . ' ' . $stad;

		// 3. Hyphen variants for streets like "Ringbaan-oost", "Ringbaan-west"
		if ( strpos( $clean, '-' ) !== false ) {
			// Strip hyphens: "Ringbaan-oost" → "Ringbaanoost"
			$no_hyphen = str_replace( '-', '', $clean );
			$variants[] = $no_hyphen . ', ' . $stad;
			// Replace hyphen with space: "Ringbaan oost"
			$space_hyphen = str_replace( '-', ' ', $clean );
			$variants[] = $space_hyphen . ', ' . $stad;
			$variants[] = $space_hyphen . ' ' . $stad;
		}

		// 4. For small towns/villages, add "gemeente" prefix
		$variants[] = $clean . ', gemeente ' . $stad;

		// Deduplicate
		$seen     = array();
		$deduped  = array();
		foreach ( $variants as $v ) {
			$key = strtolower( $v );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$deduped[]    = $v;
			}
		}

		foreach ( $deduped as $query ) {
			$coords = $this->do_geocode( $query );
			if ( $coords ) return $coords;
		}

		return null;
	}

	public function queue_size() {
		$q = get_option( self::OPTION_GEO_Q, array() );
		return is_array( $q ) ? count( $q ) : 0;
	}

	public function reset() {
		delete_option( self::OPTION_FEEDS );
		delete_option( self::OPTION_LOG );
		delete_option( self::OPTION_GEO_Q );
	}

	// ── Street parser ─────────────────────────────────────────────────────────

	private function parse_street( $raw, $city ) {
		$t = strtolower( $raw );

		// Strip priority codes
		$t = preg_replace( '/^\s*[pabrh]\s*\d+\s*/', '', $t );
		$t = preg_replace( '/^\s*[a-z]{1,4}-\d+\s*/i', '', $t );
		// Strip capcodes
		$t = preg_replace( '/(\s+\d{5,6})+\s*$/', '', $t );
		// Strip NB
		$t = preg_replace( '/\s+n\.?b\.?\s*$/i', '', $t );
		// Strip city
		if ( $city !== '' ) {
			$ce = preg_quote( $city, '/' );
			for ( $i = 0; $i < 3; $i++ ) {
				$new = preg_replace( '/\s+' . $ce . '(\s+n\.?b\.)?\s*$/i', '', $t );
				if ( $new === $t ) break;
				$t = trim( $new );
			}
		}
		$t = trim( $t );
		if ( strlen( $t ) < 2 ) return '';

		$words = preg_split( '/\s+/', $t );
		$words = array_values( array_filter( $words ) );
		if ( empty( $words ) ) return '';

		// Strategy 1: Duplicate word = primary street
		$counts = array();
		foreach ( $words as $w ) {
			$lw = strtolower( trim( $w, '.:,;()-' ) );
			if ( strlen( $lw ) >= 4 ) {
				$counts[ $lw ] = ( $counts[ $lw ] ?? 0 ) + 1;
			}
		}
		foreach ( $counts as $lw => $cnt ) {
			if ( $cnt >= 2 && ! $this->is_junk_word( $lw ) && preg_match( '/[a-z]/', $lw ) ) {
				return ucfirst( $lw );
			}
		}

		// Strategy 2: Dutch street suffix
		$suffixes = array(
			'straat', 'singel', 'dreef', 'steeg', 'haven', 'allee', 'baan',
			'laan', 'weg', 'plein', 'pad', 'dijk', 'kade', 'ring', 'park',
			'hoek', 'burg', 'vest', 'dam', 'oord', 'waard', 'hof', 'gaarde',
			'markt', 'poort', 'lei',
		);
		$prefixes = array(
			'mgr', 'mgr.', 'dr', 'dr.', 'ir', 'ir.', 'prof', 'prof.',
			'professor', 'pater', 'pastoor', 'burgemeester', 'prins',
			'prinses', 'van', 'aan', 'op', 'bij', 'ten', 'ter', 'te',
		);
		for ( $i = 0; $i < count( $words ); $i++ ) {
			$wc = strtolower( trim( $words[ $i ], '.:,;()-' ) );
			if ( $this->is_junk_word( $wc ) ) continue;
			if ( strlen( $wc ) < 3 || preg_match( '/^\d/', $wc ) ) continue;
			foreach ( $suffixes as $sfx ) {
				if ( $wc !== $sfx && substr( $wc, -strlen( $sfx ) ) === $sfx && strlen( $wc ) > strlen( $sfx ) + 1 ) {
					$street = $words[ $i ];
					if ( $i > 0 ) {
						$prev = strtolower( trim( $words[ $i - 1 ], '.:,;()-' ) );
						if ( in_array( $prev, $prefixes, true ) ) {
							$street = $words[ $i - 1 ] . ' ' . $words[ $i ];
						}
					}
					return ucwords( strtolower( $street ) );
				}
			}
		}

		// Strategy 3: Last clean words
		$clean_words = array();
		foreach ( $words as $w ) {
			$wc = strtolower( trim( $w, '.:,;()-' ) );
			if ( $this->is_junk_word( $wc ) || strlen( $wc ) < 3 || preg_match( '/^\d/', $wc ) ) continue;
			$clean_words[] = $w;
		}
		if ( empty( $clean_words ) ) return '';
		$result = implode( ' ', array_slice( $clean_words, -2 ) );
		if ( strlen( $result ) < 4 ) return '';
		return ucwords( strtolower( $result ) );
	}

	private function is_junk_word( $w ) {
		static $junk = null;
		if ( $junk === null ) {
			$junk = array(
				'p', 'a', 'b', 'h', 'p1', 'p2', 'a1', 'a2', 'b1', 'b2', 'b3',
				'brand', 'br', 'brandmelding', 'brandweer', 'ambulance', 'politie',
				'woningbrand', 'gebouwbrand', 'autobrand', 'buitenbrand', 'middelbrand',
				'ots', 'reanimatie', 'letsel', 'aanrijding', 'wegvervoer', 'hulpverlening',
				'wateroverlast', 'liftopsluiting', 'gaslucht', 'gaslek', 'storm',
				'schietpartij', 'overval', 'inbraak', 'vermissing',
				'woning', 'gebouw', 'schuur', 'loods', 'kantoor', 'winkel',
				'industrie', 'gezondheidszorg',
				'groot', 'klein', 'zeer', 'middel',
				'grip', 'grip1', 'grip2', 'grip3', 'uitbr', 'uitbr.', 'uitbr:', 've:', 've',
				'auto', 'vrachtwagen', 'motor', 'fiets', 'voetganger', 'trein',
				'naar', 'via', 'thv', 'thv:', 'sol', 'soort',
				'buiten', 'binnen', 'info', 'heterdaad:', 'heterdaad', 'geen',
				'nederland', 'nb', 'de', 'den', 'het', 'een',
				'aan', 'op', 'bij', 'in', 'te', 'ten', 'ter',
			);
		}
		return in_array( strtolower( trim( $w, '.:,;()-' ) ), $junk, true );
	}

	// ── Street cleaner ────────────────────────────────────────────────────────

	private function clean_street_for_nominatim( $straat, $stad = '' ) {
		if ( $straat === '' ) return '';
		$t = trim( $straat );

		// Remove city names
		$all_cities = array_keys( self::city_coords() );
		if ( $stad !== '' ) $all_cities[] = strtolower( $stad );
		foreach ( $all_cities as $city ) {
			$t = preg_replace( '/\b' . preg_quote( $city, '/' ) . '\b/i', ' ', $t );
		}
		$t = preg_replace( '/\bn\.?b\.?\b/i', ' ', $t );
		$t = preg_replace( '/\s+/', ' ', trim( $t ) );

		// Reject road km markers
		$check = preg_replace( '/\b(re|li)\b/i', '', $t );
		if ( preg_match( '/^\s*[\d\s,\.]+\s*$/', $check ) ) return '';
		$t = preg_replace( '/\b(re|li)\s+[\d,\.]+\s*/i', ' ', $t );
		$t = preg_replace( '/\b\d+[,.]\d+\b/', ' ', $t );
		$t = trim( $t );
		if ( $t === '' ) return '';

		// Duplicate word = primary street
		$words  = preg_split( '/\s+/', $t );
		$counts = array();
		foreach ( $words as $w ) {
			$lw = strtolower( trim( $w, '.:,;()-' ) );
			if ( strlen( $lw ) >= 4 ) $counts[ $lw ] = ( $counts[ $lw ] ?? 0 ) + 1;
		}
		foreach ( $counts as $lw => $cnt ) {
			if ( $cnt >= 2 && ( $this->word_has_street_suffix( $lw ) || strlen( $lw ) >= 6 ) ) {
				return ucfirst( $lw );
			}
		}

		// Strip noise prefixes
		$noise = array( 'letsel', 'br', 'brand', 'auto', 'naar', 'gaslucht', 'gaslek',
		                'buiten', 'binnen', 'uitbr.', 'uitbr:', 'uitbr', 'heterdaad:',
		                'geen', 'overval', 're', 'li',
		                // Building/location type words that appear before street names
		                'schoorsteen', 'gebouw', 'woning', 'schuur', 'loods', 'kantoor',
		                'winkel', 'industrie', 'tankstation', 'station', 'spoorbaan',
		                );
		$result_words = array();
		foreach ( $words as $w ) {
			$lw = strtolower( trim( $w, '.:,;()-' ) );
			if ( $lw === '' ) continue;
			if ( in_array( $lw, $noise, true ) ) continue;
			if ( preg_match( '/^\d/', $lw ) ) continue;
			$result_words[] = $w;
		}

		if ( empty( $result_words ) ) return '';
		$result = implode( ' ', $result_words );
		if ( strlen( $result ) < 3 || ! preg_match( '/[a-zA-ZÀ-ÿ]/', $result ) ) return '';

		// Short single word without suffix = skip
		if ( count( $result_words ) === 1 ) {
			$lw = strtolower( $result_words[0] );
			if ( ! $this->word_has_street_suffix( $lw ) && strlen( $lw ) < 8 ) return '';
		}

		return $result;
	}

	private function word_has_street_suffix( $word ) {
		static $suffixes = array(
			'straat', 'singel', 'dreef', 'steeg', 'haven', 'allee', 'baan',
			'laan', 'weg', 'plein', 'pad', 'dijk', 'kade', 'ring', 'park',
			'hoek', 'burg', 'vest', 'dam', 'oord', 'waard', 'hof', 'gaarde',
			'markt', 'poort', 'lei', 'akkers',
		);
		foreach ( $suffixes as $sfx ) {
			if ( substr( $word, -strlen( $sfx ) ) === $sfx && strlen( $word ) > strlen( $sfx ) + 1 ) {
				return true;
			}
		}
		return false;
	}

	private function extract_city( $raw ) {
		$t     = preg_replace( '/(\s+\d{5,6})+\s*$/', '', $raw );
		$t     = preg_replace( '/\s+n\.?b\.?\s*$/i', '', $t );
		$words = preg_split( '/\s+/', trim( $t ) );
		foreach ( array_reverse( $words ) as $w ) {
			if ( strlen( $w ) > 2 && ! preg_match( '/^\d/', $w ) && ! preg_match( '/^[a-z]{1,4}-\d/i', $w ) ) {
				return $w;
			}
		}
		return '';
	}

	// ── Geocoders: Google (primary if key set) → Photon → Nominatim ────────────

	private function do_geocode( $query ) {
		$query = trim( $query );
		if ( strlen( $query ) < 3 ) return null;

		$key    = 'sndgeo_' . md5( strtolower( $query ) );
		$cached = get_transient( $key );
		if ( false !== $cached ) return $cached ? $cached : null;

		// Try Google first if API key is set
		$api_key = get_option( 'snd_google_maps_api_key', '' );
		$result  = null;

		if ( $api_key ) {
			$result = $this->geocode_via_google( $query, $api_key );
		}

		// Fallback: Photon (Komoot)
		if ( ! $result ) {
			$result = $this->geocode_via_photon( $query );
		}

		// Last resort: Nominatim
		if ( ! $result ) {
			$result = $this->geocode_via_nominatim( $query );
		}

		if ( $result ) {
			set_transient( $key, $result, self::GEO_OK_TTL );
		}
		// Never cache failures — always retry
		return $result;
	}

	private function geocode_via_google( $query, $api_key ) {
		$url = 'https://maps.googleapis.com/maps/api/geocode/json?address='
			. rawurlencode( $query . ', Nederland' )
			. '&region=nl'
			. '&key=' . $api_key;

		$response = wp_remote_get( $url, array(
			'timeout'    => self::GEO_TIMEOUT,
			'user-agent' => get_bloginfo( 'name' ) . ' SND/1.0',
		) );

		if ( is_wp_error( $response ) ) return null;
		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) return null;

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['results'][0]['geometry']['location'] ) ) return null;
		if ( $data['status'] !== 'OK' ) return null;

		$loc = $data['results'][0]['geometry']['location'];
		return array( 'lat' => (string) $loc['lat'], 'lon' => (string) $loc['lng'] );
	}

	private function geocode_via_photon( $query ) {
		$url = 'https://photon.komoot.io/api/?q=' . rawurlencode( $query )
			. '&lang=nl&limit=1&bbox=3.31,50.75,7.09,53.55';

		$response = wp_remote_get( $url, array(
			'timeout'    => self::GEO_TIMEOUT,
			'user-agent' => get_bloginfo( 'name' ) . ' SND/1.0; ' . home_url(),
		) );

		if ( is_wp_error( $response ) ) return null;
		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) return null;

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['features'][0]['geometry']['coordinates'] ) ) return null;

		$c = $data['features'][0]['geometry']['coordinates'];
		if ( empty( $c[0] ) || empty( $c[1] ) ) return null;
		return array( 'lat' => (string) $c[1], 'lon' => (string) $c[0] ); // [lon, lat]
	}

	private function geocode_via_nominatim( $query ) {
		$response = wp_remote_get(
			'https://nominatim.openstreetmap.org/search?q=' . rawurlencode( $query ) . '&format=json&limit=1&countrycodes=nl',
			array(
				'timeout'    => self::GEO_TIMEOUT,
				'user-agent' => get_bloginfo( 'name' ) . ' SND/1.0; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) ) return null;
		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) return null;

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data[0]['lat'] ) ) return null;

		return array( 'lat' => (string) $data[0]['lat'], 'lon' => (string) $data[0]['lon'] );
	}

	private function do_reverse_geocode( $lat, $lon ) {
		$key    = 'sndrgeo_' . md5( $lat . ',' . $lon );
		$cached = get_transient( $key );
		if ( false !== $cached ) return is_array( $cached ) ? $cached : array();
		$response = wp_remote_get(
			'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' . $lat . '&lon=' . $lon,
			array( 'timeout' => self::GEO_TIMEOUT, 'user-agent' => get_bloginfo( 'name' ) . ' SND/1.0; ' . home_url() )
		);
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_transient( $key, '', self::GEO_FAIL_TTL );
			return array();
		}
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$addr   = isset( $data['address'] ) ? $data['address'] : array();
		$result = array(
			'straat' => $this->pick( $addr, array( 'road', 'pedestrian', 'footway', 'path', 'suburb' ) ),
			'stad'   => $this->pick( $addr, array( 'city', 'town', 'village', 'municipality' ) ),
		);
		set_transient( $key, $result, self::GEO_OK_TTL );
		return $result;
	}

	public function geocode( $q ) { return $this->do_geocode( $q ); }
	public function reverse_geocode( $lat, $lon ) { return $this->do_reverse_geocode( $lat, $lon ); }

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function make_record( $id, $item, $type, $display, $stad, $straat, $lat, $lon, $coord_type = 'none' ) {
		return array(
			'id'          => $id,
			'tijd'        => (int) $item->get_date( 'U' ),
			'stad'        => $stad,
			'straat'      => ucwords( strtolower( $straat ) ),
			'tekst'       => $display,
			'lat'         => $lat,
			'lon'         => $lon,
			'coord_type'  => $coord_type,
			'type'        => $type,
			'description' => wp_strip_all_tags( (string) $item->get_description() ),
		);
	}

	private function pick( $arr, $keys ) {
		foreach ( $keys as $k ) {
			if ( ! empty( $arr[ $k ] ) ) return $arr[ $k ];
		}
		return '';
	}

	private function log( $msg ) {
		$this->log[] = wp_date( 'H:i:s' ) . '  ' . $msg;
	}

	private function append_log( $msg ) {
		$log = get_option( self::OPTION_LOG, array() );
		if ( ! is_array( $log ) ) $log = array();
		$log[] = wp_date( 'H:i:s' ) . '  ' . $msg;
		update_option( self::OPTION_LOG, array_slice( $log, -200 ) );
	}

	private function maybe_notify_admin( $type, $error ) {
		if ( get_option( 'snd_notify_on_fail' ) !== '1' ) return;
		wp_mail(
			get_option( 'snd_admin_notification_email', get_bloginfo( 'admin_email' ) ),
			'[SND] P2000 feed fout: ' . $type,
			'Fout bij ophalen ' . $type . " feed:\n\n" . $error
		);
	}
}
