<?php
/**
 * URL rác đã xoá: trả 410 Gone + sitemap riêng /sitemap-gone.xml.
 *
 * Danh sách URL nằm ở inc/gone-urls.txt (mỗi dòng một URL). Sửa file đó là đủ,
 * không phải đụng tới code. Sau khi dán link, gửi
 * https://<domain>/sitemap-gone.xml vào Search Console → Sitemaps để Google
 * quét lại các URL này nhanh hơn.
 *
 * Chỉ trả 410 khi URL thực sự đang là 404 (is_404) — không bao giờ làm chết
 * một trang còn sống nếu lỡ dán nhầm link thật vào danh sách.
 *
 * @package ECSGES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Đọc danh sách, trả về mảng đường dẫn đã chuẩn hoá (không có / đầu-cuối, chữ thường).
 *
 * @return string[]
 */
function ecsges_gone_paths() {
	static $paths = null;
	if ( null !== $paths ) {
		return $paths;
	}

	$paths = array();
	$file  = get_template_directory() . '/inc/gone-urls.txt';
	if ( ! is_readable( $file ) ) {
		return $paths;
	}

	$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	foreach ( (array) $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}
		$path = wp_parse_url( $line, PHP_URL_PATH );
		$path = trim( rawurldecode( (string) $path ), '/' );
		if ( '' !== $path ) {
			$paths[ strtolower( $path ) ] = true;
		}
	}
	$paths = array_keys( $paths );

	return $paths;
}

/** Đường dẫn của request hiện tại, chuẩn hoá giống ecsges_gone_paths(). */
function ecsges_gone_request_path() {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path = wp_parse_url( $uri, PHP_URL_PATH );
	$path = trim( rawurldecode( (string) $path ), '/' );

	// Bỏ thư mục con nếu WP cài trong subfolder.
	$base = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) {
		$path = substr( $path, strlen( $base ) + 1 );
	}

	return strtolower( $path );
}

/** Rewrite rule cho sitemap. */
add_action(
	'init',
	function () {
		add_rewrite_rule( '^sitemap-gone\.xml$', 'index.php?ecsges_gone_sitemap=1', 'top' );

		// Tự nạp lại rewrite rules đúng một lần cho mỗi phiên bản.
		if ( get_option( 'ecsges_gone_rewrite_version' ) !== '1' ) {
			flush_rewrite_rules( false );
			update_option( 'ecsges_gone_rewrite_version', '1' );
		}
	}
);

add_filter(
	'query_vars',
	function ( $vars ) {
		$vars[] = 'ecsges_gone_sitemap';
		return $vars;
	}
);

/** Xuất sitemap hoặc trả 410. Priority 0: chạy trước redirect_canonical (10). */
add_action(
	'template_redirect',
	function () {
		if ( get_query_var( 'ecsges_gone_sitemap' ) ) {
			$now = gmdate( 'c' );
			status_header( 200 );
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow' );

			echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
			echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
			foreach ( ecsges_gone_paths() as $path ) {
				$loc = home_url( '/' . str_replace( '%2F', '/', rawurlencode( $path ) ) . '/' );
				echo "\t<url><loc>" . esc_url( $loc ) . '</loc><lastmod>' . esc_html( $now ) . "</lastmod></url>\n";
			}
			echo '</urlset>';
			exit;
		}

		if ( ! is_404() ) {
			return;
		}
		if ( ! in_array( ecsges_gone_request_path(), ecsges_gone_paths(), true ) ) {
			return;
		}

		status_header( 410 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!doctype html><html lang="vi"><head><meta charset="utf-8">'
			. '<meta name="robots" content="noindex,nofollow">'
			. '<title>410 – Nội dung đã bị gỡ</title></head>'
			. '<body><h1>410 – Nội dung đã bị gỡ vĩnh viễn</h1></body></html>';
		exit;
	},
	0
);

/** Khai báo sitemap trong robots.txt (robots.txt của site là bản ảo do WP/Yoast sinh). */
add_filter(
	'robots_txt',
	function ( $output ) {
		return rtrim( $output ) . "\nSitemap: " . home_url( '/sitemap-gone.xml' ) . "\n";
	},
	99
);
