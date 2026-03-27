<?php
/**
 * HTML e-mail template — Nieuws Distributie Systeem
 * Variables: $post, $outlet, $preview_images (array), $press_photo_count (int)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$portal_url   = home_url( '/persportaal/?access_code=' . rawurlencode( $outlet['access_code'] ?? '' ) );
$site_name    = get_bloginfo( 'name' );
$accent_color = get_option( 'snd_email_accent_color', '#3b82f6' );
$post_url     = get_permalink( $post->ID );
$post_date    = get_the_date( 'd F Y', $post );
$byline       = get_post_meta( $post->ID, '_snd_byline', true );
$inc_status   = get_post_meta( $post->ID, '_snd_incident_status', true );
$is_live      = get_post_meta( $post->ID, '_snd_is_live_story', true ) === '1';
$custom_note  = $outlet['custom_email_note'] ?? '';

// Photo count
if ( isset( $press_photo_count ) && $press_photo_count > 0 ) {
	$photo_count = (int) $press_photo_count;
} else {
	$raw_ids     = get_post_meta( $post->ID, '_snd_press_photos', true );
	$photo_count = ! empty( $raw_ids ) ? count( array_filter( explode( ',', $raw_ids ) ) ) : 0;
}

// Video count
$vid_json    = get_post_meta( $post->ID, '_snd_press_videos', true );
$vid_data    = ! empty( $vid_json ) ? json_decode( $vid_json, true ) : [];
$video_count = is_array( $vid_data ) ? count( $vid_data ) : 0;

// Excerpt
$excerpt = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
$excerpt = mb_strlen( $excerpt ) > 420 ? mb_substr( $excerpt, 0, 420 ) . '…' : $excerpt;

// Featured image
$hero_url = get_the_post_thumbnail_url( $post->ID, 'large' );

// Incident status
$status_cfg = [
	'onderweg'    => [ 'label' => '🚨 Fotograaf onderweg',    'bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fcd34d' ],
	'ter_plaatse' => [ 'label' => '📍 Fotograaf ter plaatse', 'bg' => '#dbeafe', 'color' => '#1e40af', 'border' => '#93c5fd' ],
	'afgerond'    => [ 'label' => '✓ Afgerond',               'bg' => '#d1fae5', 'color' => '#065f46', 'border' => '#6ee7b7' ],
];
$sc = $inc_status ? ( $status_cfg[ $inc_status ] ?? null ) : null;
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="nl">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( $post->post_title ); ?></title>
<style type="text/css">
body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}
table,td{mso-table-lspace:0pt;mso-table-rspace:0pt;}
img{-ms-interpolation-mode:bicubic;border:0;outline:none;text-decoration:none;display:block;}
body{margin:0!important;padding:0!important;background-color:#f1f5f9;}
a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important;}
@media only screen and (max-width:620px){
  .email-wrap{width:100%!important;}
  .pad{padding:24px 18px!important;}
  .hero{height:200px!important;}
}
</style>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;" bgcolor="#f1f5f9">
<table border="0" cellpadding="0" cellspacing="0" width="100%" style="background:#f1f5f9;">
<tr><td align="center" style="padding:32px 16px 48px;">

<table border="0" cellpadding="0" cellspacing="0" width="600" class="email-wrap" style="max-width:600px;width:100%;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.10);">

<!-- TOP BAR -->
<tr>
  <td bgcolor="#0f172a" style="padding:14px 28px;border-radius:12px 12px 0 0;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
      <td><p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:#64748b;"><?php echo esc_html( $site_name ); ?></p></td>
      <td align="right"><p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:11px;color:#475569;"><?php echo esc_html( $post_date ); ?></p></td>
    </tr></table>
  </td>
</tr>

<?php if ( $is_live ) : ?>
<!-- LIVE BANNER -->
<tr><td bgcolor="#ef4444" style="padding:8px 28px;text-align:center;">
  <p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#fff;">🔴 LIVE verhaal — wordt continu bijgewerkt</p>
</td></tr>
<?php endif; ?>

<?php if ( $sc ) : ?>
<!-- STATUS BANNER -->
<tr><td bgcolor="<?php echo esc_attr( $sc['bg'] ); ?>" style="padding:10px 28px;border-bottom:1px solid <?php echo esc_attr( $sc['border'] ); ?>;">
  <p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:13px;font-weight:600;color:<?php echo esc_attr( $sc['color'] ); ?>;"><?php echo esc_html( $sc['label'] ); ?></p>
</td></tr>
<?php endif; ?>

<?php if ( $hero_url ) : ?>
<!-- HERO IMAGE -->
<tr><td style="padding:0;line-height:0;font-size:0;">
  <img src="<?php echo esc_url( $hero_url ); ?>" alt="<?php echo esc_attr( $post->post_title ); ?>" width="600" class="hero" style="width:100%;max-width:600px;height:260px;object-fit:cover;display:block;">
</td></tr>
<?php endif; ?>

<!-- MAIN BODY -->
<tr><td bgcolor="#ffffff" class="pad" style="padding:36px 36px 28px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">

  <p style="margin:0 0 8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:13px;color:#94a3b8;">
    Beste redactie van <strong style="color:#334155;"><?php echo esc_html( $outlet['name'] ); ?></strong>,
  </p>

  <h1 style="margin:0 0 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:24px;font-weight:800;line-height:1.3;color:#0f172a;letter-spacing:-.3px;">
    <?php echo esc_html( $post->post_title ); ?>
  </h1>

  <?php if ( $byline ) : ?>
  <p style="margin:-8px 0 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:12px;color:#64748b;font-style:italic;">📷 <?php echo esc_html( $byline ); ?></p>
  <?php endif; ?>

  <!-- accent line -->
  <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:20px;"><tr>
    <td width="48" style="height:3px;background:<?php echo esc_attr( $accent_color ); ?>;border-radius:2px;font-size:0;line-height:0;">&nbsp;</td>
    <td style="height:3px;background:#f1f5f9;font-size:0;line-height:0;">&nbsp;</td>
  </tr></table>

  <p style="margin:0 0 24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:15px;line-height:1.75;color:#334155;">
    <?php echo nl2br( esc_html( $excerpt ) ); ?>
  </p>

</td></tr>

<?php if ( ! empty( $preview_images ) ) :
  $show = array_slice( $preview_images, 0, 3 );
  $n    = count( $show );
?>
<!-- PREVIEW IMAGES -->
<tr><td bgcolor="#ffffff" style="padding:0 36px 28px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">
  <p style="margin:0 0 10px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.9px;color:#94a3b8;">Preview</p>
  <table border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
  <?php foreach ( $show as $i => $img_id ) :
    $url = wp_get_attachment_image_url( $img_id, 'medium' );
    if ( ! $url ) continue;
    $w = $n === 1 ? '100%' : ( $n === 2 ? '49%' : '32%' );
    $h = $n === 1 ? '220px' : '130px';
  ?>
    <td width="<?php echo $w; ?>" style="<?php echo $i > 0 ? 'padding-left:6px;' : ''; ?>vertical-align:top;">
      <img src="<?php echo esc_url( $url ); ?>" alt="" style="width:100%;height:<?php echo $h; ?>;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0;">
    </td>
  <?php endforeach; ?>
  </tr></table>
  <?php if ( count( $preview_images ) > 3 ) : ?>
  <p style="margin:8px 0 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:11px;color:#94a3b8;text-align:right;">+<?php echo count( $preview_images ) - 3; ?> meer in portaal</p>
  <?php endif; ?>
</td></tr>
<?php endif; ?>

<!-- MEDIA BADGES + CTA -->
<tr><td bgcolor="#ffffff" class="pad" style="padding:0 36px 36px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">

  <?php if ( $photo_count > 0 || $video_count > 0 ) : ?>
  <table border="0" cellpadding="0" cellspacing="0" style="margin-bottom:22px;"><tr>
    <?php if ( $photo_count > 0 ) : ?>
    <td style="padding-right:8px;">
      <span style="display:inline-block;background:#eff6ff;border:1px solid #bfdbfe;border-radius:20px;padding:5px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:12px;font-weight:600;color:#1d4ed8;">
        📷 <?php echo $photo_count; ?> foto<?php echo $photo_count > 1 ? "'s" : ''; ?> beschikbaar
      </span>
    </td>
    <?php endif; ?>
    <?php if ( $video_count > 0 ) : ?>
    <td>
      <span style="display:inline-block;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:20px;padding:5px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:12px;font-weight:600;color:#166534;">
        🎬 Video's beschikbaar
      </span>
    </td>
    <?php endif; ?>
  </tr></table>
  <?php endif; ?>

  <!-- CTA BUTTON -->
  <table border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
    <td bgcolor="<?php echo esc_attr( $accent_color ); ?>" style="border-radius:8px;">
      <a href="<?php echo esc_url( $portal_url ); ?>" target="_blank"
        style="display:block;padding:16px 28px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;text-align:center;border-radius:8px;">
        📰 Bekijk volledig bericht + media →
      </a>
    </td>
  </tr></table>

  <?php if ( $post_url ) : ?>
  <p style="margin:12px 0 0;text-align:center;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:12px;color:#94a3b8;">
    Of lees het <a href="<?php echo esc_url( $post_url ); ?>" target="_blank" style="color:<?php echo esc_attr( $accent_color ); ?>;text-decoration:underline;">publieke artikel op de website</a>
  </p>
  <?php endif; ?>

</td></tr>

<!-- FEATURES ROW -->
<tr><td bgcolor="#f8fafc" style="padding:22px 36px;border:1px solid #e2e8f0;border-top:none;">
  <table border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
    <td width="33%" valign="top" align="center" style="padding-right:10px;">
      <p style="margin:0 0 3px;font-size:20px;">📰</p>
      <p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:11px;font-weight:600;color:#334155;">Volledig artikel</p>
      <p style="margin:2px 0 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:10px;color:#94a3b8;line-height:1.4;">Met context &amp; achtergrond</p>
    </td>
    <td width="33%" valign="top" align="center" style="padding-right:10px;">
      <p style="margin:0 0 3px;font-size:20px;">📷</p>
      <p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:11px;font-weight:600;color:#334155;">Hoge res. foto's</p>
      <p style="margin:2px 0 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:10px;color:#94a3b8;line-height:1.4;">Watermerkvrij, ZIP-download</p>
    </td>
    <td width="33%" valign="top" align="center">
      <p style="margin:0 0 3px;font-size:20px;">🔔</p>
      <p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:11px;font-weight:600;color:#334155;">Live updates</p>
      <p style="margin:2px 0 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:10px;color:#94a3b8;line-height:1.4;">Portaal ververst automatisch</p>
    </td>
  </tr></table>
</td></tr>

<?php if ( $custom_note ) : ?>
<!-- CUSTOM NOTE FOR THIS OUTLET -->
<tr><td bgcolor="#fffbeb" style="padding:14px 36px;border:1px solid #fde68a;border-top:none;">
  <p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:12px;color:#92400e;line-height:1.6;">
    📋 <?php echo nl2br( esc_html( $custom_note ) ); ?>
  </p>
</td></tr>
<?php endif; ?>

<!-- FOOTER -->
<tr><td bgcolor="#0f172a" style="padding:20px 36px;border-radius:0 0 12px 12px;">
  <table border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
    <td valign="middle">
      <p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:12px;color:#475569;">&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php echo esc_html( $site_name ); ?></p>
      <p style="margin:3px 0 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:11px;color:#334155;line-height:1.5;">Exclusief voor geselecteerde media-contacten. Uw link is persoonlijk.</p>
    </td>
    <td align="right" valign="middle" style="padding-left:16px;">
      <a href="<?php echo esc_url( $portal_url ); ?>" target="_blank"
        style="display:inline-block;padding:8px 14px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:6px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:11px;font-weight:600;color:rgba(255,255,255,.7);text-decoration:none;white-space:nowrap;">
        Portaal →
      </a>
    </td>
  </tr></table>
</td></tr>

</table>
</td></tr></table>
</body>
</html>
