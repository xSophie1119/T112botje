<?php
/**
 * Update e-mail template — Nieuws Distributie Systeem
 * Variables: $post, $outlet, $subject, $body_text, $portal_url,
 *            $post_url, $site_name, $accent_color, $custom_note
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! isset( $post ) || ! $post ) return;
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="nl">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?php echo esc_html( $subject ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;">
<table border="0" cellpadding="0" cellspacing="0" width="100%" style="background:#f1f5f9;"><tr><td align="center" style="padding:32px 16px 48px;">
<table border="0" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.10);">

	<!-- TOP BAR -->
	<tr><td bgcolor="#0f172a" style="padding:14px 28px;border-radius:12px 12px 0 0;">
		<table border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
			<td><p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:#64748b;"><?php echo esc_html( $site_name ); ?></p></td>
			<td align="right"><p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:11px;color:#475569;"><?php echo esc_html( wp_date( 'd F Y' ) ); ?></p></td>
		</tr></table>
	</td></tr>

	<!-- UPDATE BADGE -->
	<tr><td bgcolor="<?php echo esc_attr( $accent_color ); ?>" style="padding:8px 28px;text-align:center;">
		<p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#fff;">
			&#x1F514; Update bij eerder bericht
		</p>
	</td></tr>

	<!-- BODY -->
	<tr><td bgcolor="#ffffff" style="padding:32px 36px 0;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">
		<p style="margin:0 0 6px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:13px;color:#94a3b8;">
			Beste redactie van <strong style="color:#334155;"><?php echo esc_html( $outlet['name'] ); ?></strong>,
		</p>
		<h2 style="margin:0 0 6px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:13px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">
			Update bij: <span style="color:#0f172a;"><?php echo esc_html( $post->post_title ); ?></span>
		</h2>
		<table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:20px;margin-top:14px;"><tr>
			<td width="48" style="height:3px;background:<?php echo esc_attr( $accent_color ); ?>;border-radius:2px;font-size:0;line-height:0;">&nbsp;</td>
			<td style="height:3px;background:#f1f5f9;font-size:0;line-height:0;">&nbsp;</td>
		</tr></table>
		<div style="font-size:15px;line-height:1.75;color:#334155;"><?php echo wpautop( wp_kses_post( $body_text ) ); ?></div>
	</td></tr>

	<!-- CTA -->
	<tr><td bgcolor="#ffffff" style="padding:28px 36px 36px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">
		<p style="margin:0 0 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:13px;color:#64748b;">
			Bekijk het bijgewerkte bericht inclusief eventuele nieuwe foto's via uw persoonlijk portaal:
		</p>
		<table border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
			<td bgcolor="<?php echo esc_attr( $accent_color ); ?>" style="border-radius:8px;">
				<a href="<?php echo esc_url( $portal_url ); ?>" target="_blank"
					style="display:block;padding:14px 28px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;text-align:center;border-radius:8px;">
					&#x1F4F0; Naar persoonlijk portaal &rarr;
				</a>
			</td>
		</tr></table>
		<?php if ( $post_url ) : ?>
		<p style="margin:12px 0 0;text-align:center;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:12px;color:#94a3b8;">
			Of lees het <a href="<?php echo esc_url( $post_url ); ?>" target="_blank" style="color:<?php echo esc_attr( $accent_color ); ?>;text-decoration:underline;">publieke artikel</a>
		</p>
		<?php endif; ?>
	</td></tr>

	<?php if ( $custom_note ) : ?>
	<!-- CUSTOM NOTE -->
	<tr><td bgcolor="#fffbeb" style="padding:14px 36px;border:1px solid #fde68a;border-top:none;">
		<p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:12px;color:#92400e;line-height:1.6;">
			&#x1F4CB; <?php echo nl2br( esc_html( $custom_note ) ); ?>
		</p>
	</td></tr>
	<?php endif; ?>

	<!-- FOOTER -->
	<tr><td bgcolor="#0f172a" style="padding:18px 36px;border-radius:0 0 12px 12px;">
		<p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:12px;color:#475569;">
			&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php echo esc_html( $site_name ); ?>
			&mdash; <a href="<?php echo esc_url( $portal_url ); ?>" style="color:<?php echo esc_attr( $accent_color ); ?>;text-decoration:none;">Naar portaal &rarr;</a>
		</p>
	</td></tr>

</table>
</td></tr></table>
</body>
</html>
