<?php
/**
 * Turns a host page into a direct file link.
 *
 * Supported hosts, and what each one needs:
 *
 * - HubCloud  /drive/{id} -> interstitial -> signed Cloudflare R2 URL
 * - Pixeldrain /u/{id}   -> public JSON API -> direct download URL
 * - MEGA     /file/{id}   -> public JSON API -> encrypted direct URL + file key
 * - Argon    /downlead/{id} -> JSON API -> quality list with direct links
 * - direct files and anything else is probed with a range request
 *
 * A host that cannot be resolved is never fatal: the payload keeps the
 * original URL and explains why it could not be resolved.
 *
 * @package RAI
 */

namespace RAI;

final class Resolver {

	/**
	 * Resolve a single URL.
	 *
	 * @param string $url     URL taken from a post.
	 * @param array  $options timeout override.
	 * @return array Resolution payload.
	 */
	public static function resolve( string $url, array $options = array() ): array {
		$host    = Hosts::classify( $url );
		$timeout = (int) ( $options['timeout'] ?? Http::TIMEOUT );

		$result = array(
			'url'         => $url,
			'host'        => $host['id'],
			'host_label'  => $host['label'],
			'host_id'     => $host['id'],
			'resolved'    => false,
			'direct_url'  => null,
			'filename'    => null,
			'size'        => null,
			'size_human'  => null,
			'mime_type'   => null,
			'expires_at'  => null,
			'requires_client' => false,
			'reason'      => null,
			'meta'        => array(),
			'resolved_at' => gmdate( 'c' ),
		);

		try {
			switch ( $host['id'] ) {
				case 'hubcloud':
					self::resolve_hubcloud( $url, $result, $timeout );
					break;

				case 'pixeldrain':
					self::resolve_pixeldrain( $url, $result, $timeout );
					break;

				case 'mega':
					self::resolve_mega( $url, $result, $timeout );
					break;

				case 'argon':
					self::resolve_argon( $url, $result, $timeout );
					break;

				case 'zipcloud':
					$result['reason'] = 'Host is behind a Cloudflare challenge; open the original link.';
					break;

				case 'direct':
					self::resolve_direct( $url, $result, $timeout );
					break;

				default:
					$result['reason'] = 'Unsupported host: ' . $host['host'];
					break;
			}
		} catch ( \Throwable $error ) {
			$result['reason'] = 'Resolver error: ' . $error->getMessage();
		}

		if ( null !== $result['size'] ) {
			$result['size_human'] = Http::human_bytes( $result['size'] );
		}

		if ( null !== $result['direct_url'] ) {
			$result['resolved'] = true;
		}

		return $result;
	}

	/**
	 * HubCloud: /drive/{id} -> interstitial -> signed R2 object URL.
	 */
	private static function resolve_hubcloud( string $url, array &$result, int $timeout ): void {
		$host = Hosts::classify( $url );
		$id   = $host['file_id'];

		if ( ! $id ) {
			$result['reason'] = 'Could not read the HubCloud file id.';

			return;
		}

		$page = Http::request(
			'https://hubcloud.mov/drive/' . rawurlencode( $id ),
			array( 'timeout' => $timeout )
		);

		if ( ! $page['ok'] ) {
			$result['reason'] = 'HubCloud page returned HTTP ' . $page['status'] . '.';

			return;
		}

		if ( ! preg_match( '#https://gamerxyt\.com/hubcloud\.php\?[^"\'\s<]+#i', $page['body'], $match ) ) {
			$result['reason'] = 'HubCloud interstitial did not contain a download handler.';

			return;
		}

		$handler = Html::decode( $match[0] );
		$file    = Http::request( $handler, array( 'timeout' => $timeout ) );

		if ( ! $file['ok'] ) {
			$result['reason'] = 'HubCloud download handler returned HTTP ' . $file['status'] . '.';

			return;
		}

		$direct = null;
		if ( preg_match_all( '#https://[a-z0-9-]+\.r2\.cloudflarestorage\.com/[^"\'\s<]+#i', $file['body'], $matches ) ) {
			$direct = Html::decode( $matches[0][0] );
		}

		$candidates = array();
		if ( preg_match_all( '#https://gpdl\.[^"\'\s<]+#i', $file['body'], $matches ) ) {
			$candidates = array_map( static fn( $url ) => Html::decode( $url ), $matches[0] );
		}

		if ( ! $direct ) {
			if ( $candidates ) {
				$result['direct_url'] = $candidates[0];
				$result['meta']['fallback'] = 'proxy';

				return;
			}
			$result['reason'] = 'HubCloud did not expose a file URL.';

			return;
		}

		$result['direct_url'] = $direct;
		$result['meta']['source']  = 'hubcloud';
		$result['meta']['candidates'] = $candidates;

		// The signed URL carries the filename and the expiry.
		$query = (string) parse_url( $direct, PHP_URL_QUERY );
		parse_str( $query, $params );

		if ( ! empty( $params['response-content-disposition'] ) ) {
			$filename = Http::filename_from_disposition( $params['response-content-disposition'] );
			if ( $filename ) {
				$result['filename'] = $filename;
			}
		}

		if ( ! empty( $params['X-Amz-Date'] ) && ! empty( $params['X-Amz-Expires'] ) ) {
			$date = \DateTime::createFromFormat( 'Ymd\THis\Z', $params['X-Amz-Date'], new \DateTimeZone( 'UTC' ) );
			if ( $date ) {
				$expires = clone $date;
				$expires->modify( '+' . (int) $params['X-Amz-Expires'] . ' seconds' );
				$result['expires_at'] = $expires->format( 'c' );
			}
		}

		$probe = Http::probe( $direct );
		if ( null !== $probe['size'] ) {
			$result['size'] = $probe['size'];
		}
		if ( null === $result['filename'] && $probe['filename'] ) {
			$result['filename'] = $probe['filename'];
		}
		if ( $probe['mime'] ) {
			$result['mime_type'] = $probe['mime'];
		}
	}

	/**
	 * Pixeldrain: /u/{id} -> /api/file/{id}/info -> /api/file/{id}?download
	 */
	private static function resolve_pixeldrain( string $url, array &$result, int $timeout ): void {
		$host = Hosts::classify( $url );
		$id   = $host['file_id'];

		if ( ! $id ) {
			$result['reason'] = 'Could not read the Pixeldrain file id.';

			return;
		}

		$response = Http::request(
			'https://pixeldrain.com/api/file/' . rawurlencode( $id ) . '/info',
			array( 'timeout' => $timeout )
		);

		if ( ! $response['ok'] ) {
			$result['reason'] = 'Pixeldrain returned HTTP ' . $response['status'] . '.';

			return;
		}

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) ) {
			$result['reason'] = 'Pixeldrain returned an unexpected payload.';

			return;
		}

		if ( isset( $data['success'] ) && ! $data['success'] ) {
			$result['reason'] = 'Pixeldrain file is not available (error ' . ( $data['code'] ?? 'unknown' ) . ').';

			return;
		}

		$result['direct_url'] = 'https://pixeldrain.com/api/file/' . rawurlencode( $id ) . '?download';
		$result['filename']  = $data['name'] ?? null;
		$result['size']      = isset( $data['size'] ) ? (int) $data['size'] : null;
		$result['mime_type'] = $data['mime_type'] ?? null;
		$result['meta']      = array(
			'source'       => 'pixeldrain',
			'sha256'       => $data['hash_sha256'] ?? null,
			'uploaded_at'  => $data['date_upload'] ?? null,
			'downloads'    => $data['downloads'] ?? null,
			'thumbnail'    => ! empty( $data['id'] ) ? 'https://pixeldrain.com/api/file/' . rawurlencode( $data['id'] ) . '/thumbnail' : null,
		);
	}

	/**
	 * MEGA: /file/{id}#key -> g.api.mega.co.nz -> encrypted download URL.
	 */
	private static function resolve_mega( string $url, array &$result, int $timeout ): void {
		$host = Hosts::classify( $url );
		$raw  = (string) $host['file_id'];

		if ( ! str_contains( $raw, '#' ) ) {
			$result['reason'] = 'MEGA link is missing its file key.';

			return;
		}

		list( $file_id, $key_fragment ) = explode( '#', $raw, 2 );

		$response = Http::request(
			'https://g.api.mega.co.nz/cs?id=' . wp_rand_id(),
			array(
				'method'  => 'POST',
				'body'    => wp_json_encode( array( array( 'a' => 'g', 'g' => 1, 'p' => $file_id ) ) ),
				'headers' => array( 'Content-Type: application/json' ),
				'timeout' => $timeout,
			)
		);

		if ( ! $response['ok'] ) {
			$result['reason'] = 'MEGA API returned HTTP ' . $response['status'] . '.';

			return;
		}

		$payload = json_decode( $response['body'], true );
		if ( ! is_array( $payload ) || ! isset( $payload[0] ) || ! is_array( $payload[0] ) ) {
			$result['reason'] = 'MEGA API returned an unexpected payload.';

			return;
		}

		$entry = $payload[0];

		// A bare integer is a MEGA error code, e.g. -9 for a missing file.
		if ( isset( $entry[0] ) && is_int( $entry[0] ) ) {
			$result['reason'] = 'MEGA error ' . $entry[0] . ' (the file is usually deleted or expired).';

			return;
		}

		if ( empty( $entry['g'] ) ) {
			$result['reason'] = 'MEGA did not return a download URL.';

			return;
		}

		$direct = $entry['g'];
		if ( 0 === stripos( $direct, 'http://' ) ) {
			$direct = 'https://' . substr( $direct, 7 );
		}

		$result['direct_url'] = $direct;
		$result['size']       = isset( $entry['s'] ) ? (int) $entry['s'] : null;
		$result['meta']       = array(
			'source'          => 'mega',
			'file_id'         => $file_id,
			'key'             => $key_fragment,
			'attributes'      => $entry['at'] ?? null,
			'msd'             => $entry['msd'] ?? null,
			'file_attributes' => $entry['fa'] ?? null,
			'decryption'      => 'AES-128-CTR with the link key; offset ' . self::mega_offset( $entry['at'] ?? '' ),
		);

		$attributes = self::mega_decrypt_attributes( $key_fragment, (string) ( $entry['at'] ?? '' ) );
		if ( $attributes && ! empty( $attributes['n'] ) ) {
			$result['filename'] = $attributes['n'];
		}

		// MEGA stores the payload encrypted; a MEGA aware client is required.
		$result['requires_client'] = true;
		if ( null === $result['filename'] ) {
			$result['reason'] = 'MEGA link resolved; the filename lives in the encrypted attributes.';
		}
	}

	/**
	 * Argon: /downlead/{id} -> JSON API -> quality list.
	 */
	private static function resolve_argon( string $url, array &$result, int $timeout ): void {
		$host = Hosts::classify( $url );
		$id   = $host['file_id'];

		if ( ! $id ) {
			$result['reason'] = 'Could not read the Argon video id.';

			return;
		}

		$page_url = 'https://argon.razorshell.space/downlead/' . rawurlencode( $id );
		$page     = Http::request( $page_url, array( 'timeout' => $timeout ) );

		if ( ! $page['ok'] ) {
			$result['reason'] = 'Argon page returned HTTP ' . $page['status'] . '.';

			return;
		}

		if ( ! preg_match( '#window\.juicyData\s*=\s*(\{.*?\})\s*;?\s*</script>#s', $page['body'], $match ) ) {
			$result['reason'] = 'Argon page did not contain its API configuration.';

			return;
		}

		$config = json_decode( $match[1], true );
		$route  = $config['data']['routes']['links'] ?? '';
		$token  = $config['data']['token'] ?? '';

		if ( ! $route || ! $token ) {
			$result['reason'] = 'Argon page did not expose a links route.';

			return;
		}

		$response = Http::request(
			'https://argon.razorshell.space' . $route,
			array(
				'method'  => 'POST',
				'body'    => wp_json_encode( array( 'captcha' => null, '_token' => $token ) ),
				'headers' => array( 'Content-Type: application/json', 'Accept: application/json' ),
				'referer' => $page_url,
				'timeout' => $timeout,
			)
		);

		if ( ! $response['ok'] ) {
			$result['reason'] = 'Argon API returned HTTP ' . $response['status'] . '.';

			return;
		}

		$payload = json_decode( $response['body'], true );
		if ( ! is_array( $payload ) || empty( $payload['success'] ) || empty( $payload['qualities'] ) ) {
			$result['reason'] = 'Argon API did not return any download links.';

			return;
		}

		$qualities = array();
		foreach ( (array) $payload['qualities'] as $quality ) {
			if ( empty( $quality['link'] ) ) {
				continue;
			}
			$qualities[] = array(
				'label' => $quality['label'] ?? null,
				'link'  => $quality['link'],
				'size'  => $quality['size'] ?? null,
			);
		}

		if ( ! $qualities ) {
			$result['reason'] = 'Argon API returned an empty quality list.';

			return;
		}

		$first = $qualities[0];
		$result['direct_url'] = $first['link'];
		$result['filename']   = $first['label'];
		$result['meta']       = array(
			'source'    => 'argon',
			'qualities' => $qualities,
		);
	}

	/**
	 * A URL that already points at a file.
	 */
	private static function resolve_direct( string $url, array &$result, int $timeout ): void {
		$probe = Http::probe( $url );

		if ( 0 === $probe['status'] || $probe['status'] >= 400 ) {
			$result['reason'] = 'The file could not be reached (HTTP ' . $probe['status'] . ').';

			return;
		}

		$result['direct_url'] = $url;
		$result['size']       = $probe['size'];
		$result['filename']   = $probe['filename'];
		$result['mime_type']  = $probe['mime'];
		$result['meta']       = array( 'source' => 'direct' );
	}

	/**
	 * Decrypt MEGA file attributes when the link uses the classic key layout.
	 *
	 * @return array|null Attribute map, or null when the layout is not recognised.
	 */
	private static function mega_decrypt_attributes( string $key_fragment, string $at ): ?array {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}

		$key = self::b64url_decode( $key_fragment );
		if ( null === $key || 32 !== strlen( $key ) ) {
			return null;
		}

		$data = self::b64url_decode( $at );
		if ( null === $data || 0 !== strlen( $data ) % 16 ) {
			return null;
		}

		$plain = openssl_decrypt( $data, 'AES-128-CBC', substr( $key, 0, 16 ), OPENSSL_RAW_DATA, str_repeat( "\0", 16 ) );
		if ( false === $plain || 0 !== strpos( $plain, 'MEGA' ) ) {
			return null;
		}

		$attributes = json_decode( substr( $plain, 4 ), true );

		return is_array( $attributes ) ? $attributes : null;
	}

	/**
	 * Byte offset of the MEGA payload inside the encrypted stream.
	 */
	private static function mega_offset( string $at ): int {
		$decoded = self::b64url_decode( $at );
		if ( null === $decoded ) {
			return 0;
		}

		return 0;
	}

	/**
	 * Decode base64url without padding.
	 */
	private static function b64url_decode( string $value ): ?string {
		$value = strtr( trim( $value ), '-_', '+/' );
		$pad   = strlen( $value ) % 4;
		if ( $pad ) {
			$value .= str_repeat( '=', 4 - $pad );
		}
		$decoded = base64_decode( $value, true );

		return false === $decoded ? null : $decoded;
	}


}
