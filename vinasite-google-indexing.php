<?php
/**
 * Plugin Name: Vinasite Google Indexing
 * Plugin URI: https://github.com/dinhducmarketing-droid/vinasite-google-indexing
 * Description: Gửi URL lên Google Indexing API bằng service account. Tự động gửi index khi publish + chạy hàng ngày theo 2 kiểu: Gửi thẳng (không cần Search Console) hoặc Thông minh (hỏi đã index chưa rồi chỉ gửi bài chưa index) + bulk submit + log + retry chống timeout.
 * Version: 1.3
 * Author: Vinasite
 * Author URI: https://vinasite.com.vn/
 * Update URI: https://github.com/dinhducmarketing-droid/vinasite-google-indexing
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------------------------
 * Tự cập nhật từ GitHub — cùng cơ chế với theme VinaSite (thư viện Plugin
 * Update Checker nhúng sẵn, KHÔNG cần plugin ngoài).
 *
 * Nhờ vậy nâng cấp tính năng ở đây thì mọi site đang cài đều thấy nút
 * "Cập nhật" trong Plugins như plugin trên wordpress.org.
 *
 * Repo Public → không cần token. Nếu chuyển sang Private thì thêm:
 *   $vgi_update_checker->setAuthentication('TOKEN');
 * ---------------------------------------------------------------------- */
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

$vgi_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/dinhducmarketing-droid/vinasite-google-indexing/',
    __FILE__,
    'vinasite-google-indexing'
);

// Đọc version thẳng từ nhánh main, khỏi phải tạo Release cho mỗi bản.
$vgi_update_checker->setBranch('main');

class Vinasite_Google_Indexing {
	const OPT_JSON  = 'vgi_service_account_json';
	const OPT_TYPES = 'vgi_post_types';
	const OPT_LOG   = 'vgi_log';
	const SCOPE     = 'https://www.googleapis.com/auth/indexing';
	// Scope đọc Search Console (dùng cho URL Inspection API)
	const SCOPE_GSC = 'https://www.googleapis.com/auth/webmasters.readonly';
	// aud của JWT = endpoint token chuẩn của Google (Google validate theo giá trị này)
	const TOKEN_AUD = 'https://oauth2.googleapis.com/token';
	// NƠI POST lấy token: dùng www.googleapis.com vì oauth2.googleapis.com bị chặn IPv6 trên server này
	const TOKEN_URL = 'https://www.googleapis.com/oauth2/v4/token';
	const ENDPOINT  = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
	const INSPECT   = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

	/* --- Quét index hàng ngày --- */
	const OPT_CRON_ON      = 'vgi_cron_on';        // '1' = bật
	const OPT_SITE_URL     = 'vgi_site_url';       // property GSC, vd https://vanphongluatsu.com.vn/
	const OPT_INSPECT_MAX  = 'vgi_inspect_max';    // số URL kiểm tra/ngày (quota Google 2.000)
	const OPT_SUBMIT_MAX   = 'vgi_submit_max';     // số URL gửi/ngày   (quota Google 200)
	const OPT_STATS        = 'vgi_stats';          // kết quả lần chạy gần nhất
	const CRON_HOOK        = 'vgi_daily_scan';
	// Kiểu chạy hàng ngày:
	//  'smart'  = hỏi Google đã index chưa (URL Inspection, CẦN quyền Search Console)
	//             rồi chỉ gửi bài chưa index. Tiết kiệm quota nhưng cần cấu hình GSC.
	//  'direct' = gửi thẳng bài mới nhất lên Indexing API, KHÔNG hỏi Search Console.
	//             Chạy được ngay chỉ với quyền Indexing API.
	const OPT_DAILY_MODE   = 'vgi_daily_mode';     // 'smart' (mặc định) | 'direct'
	const OPT_RESUBMIT_DAYS = 'vgi_resubmit_days'; // chế độ 'direct': chỉ gửi lại 1 URL sau N ngày
	const META_CHECKED     = '_vgi_checked';       // timestamp lần kiểm tra cuối
	const META_STATE       = '_vgi_state';         // coverageState của Google
	const META_INDEXED     = '_vgi_indexed';       // '1' đã index / '0' chưa
	const META_SENT        = '_vgi_sent';          // timestamp lần gửi cuối
	const TIME_BUDGET      = 90;                   // giây/lần chạy, tránh treo cron

	public function __construct() {
		add_action('admin_menu', [$this, 'menu']);
		add_action('transition_post_status', [$this, 'on_transition'], 20, 3);
		add_action('admin_post_vgi_save', [$this, 'handle_save']);
		add_action('admin_post_vgi_test', [$this, 'handle_test']);
		add_action('admin_post_vgi_bulk', [$this, 'handle_bulk']);
		add_action('admin_post_vgi_scan_now', [$this, 'handle_scan_now']);
		add_action(self::CRON_HOOK, [$this, 'run_daily']);
	}

	/* ---------- Cron hàng ngày: chạy đúng kiểu đã chọn ---------- */
	public function run_daily() {
		if (get_option(self::OPT_DAILY_MODE, 'smart') === 'direct') {
			$this->daily_direct_submit();
		} else {
			$this->daily_scan();
		}
	}

	/* ---------- Bật/tắt lịch chạy ---------- */
	public static function sync_schedule() {
		$on = get_option(self::OPT_CRON_ON) === '1';
		$next = wp_next_scheduled(self::CRON_HOOK);
		if ($on && !$next) {
			// chạy lần đầu vào 3h sáng hôm sau (giờ site), sau đó mỗi ngày
			$t = strtotime('tomorrow 03:00', current_time('timestamp')) - (int) (get_option('gmt_offset') * HOUR_IN_SECONDS);
			wp_schedule_event($t, 'daily', self::CRON_HOOK);
		} elseif (!$on && $next) {
			wp_unschedule_event($next, self::CRON_HOOK);
		}
	}

	/* ---------- wp_remote_post + tự thử lại khi timeout/kết nối lỗi ---------- */
	private function post_retry($url, $args, $tries = 3) {
		$args['timeout'] = 20;
		$resp = null;
		for ($i = 0; $i < $tries; $i++) {
			$resp = wp_remote_post($url, $args);
			if (!is_wp_error($resp)) return $resp;
			if ($i < $tries - 1) usleep(800000); // chờ 0.8s rồi thử lại
		}
		return $resp;
	}

	/* ---------- Auth: service account JWT -> access token ---------- */
	private function get_access_token($scope = self::SCOPE) {
		$json = get_option(self::OPT_JSON);
		if (!$json) return new WP_Error('no_json', 'Chưa dán service account JSON');
		$sa = json_decode($json, true);
		if (!$sa || empty($sa['client_email']) || empty($sa['private_key']))
			return new WP_Error('bad_json', 'JSON không hợp lệ (thiếu client_email/private_key)');

		// Cache RIÊNG theo scope — token scope indexing không dùng được cho Search Console.
		$cache_key = 'vgi_token_' . md5($scope);
		$cache = get_transient($cache_key);
		if ($cache) return $cache;

		$now = time();
		$b64 = function ($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); };
		$header = $b64(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
		$claim  = $b64(wp_json_encode([
			'iss'   => $sa['client_email'],
			'scope' => $scope,
			'aud'   => self::TOKEN_AUD,
			'exp'   => $now + 3600,
			'iat'   => $now,
		]));
		$signing_input = $header . '.' . $claim;
		$sig = '';
		if (!openssl_sign($signing_input, $sig, $sa['private_key'], 'SHA256'))
			return new WP_Error('sign', 'Ký JWT thất bại (private_key sai?)');
		$jwt = $signing_input . '.' . $b64($sig);

		$resp = $this->post_retry(self::TOKEN_URL, [
			'body' => [
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			],
		]);
		if (is_wp_error($resp)) return $resp;
		$body = json_decode(wp_remote_retrieve_body($resp), true);
		if (empty($body['access_token']))
			return new WP_Error('token', 'Không lấy được token: ' . wp_remote_retrieve_body($resp));
		set_transient($cache_key, $body['access_token'], 3300);
		return $body['access_token'];
	}

	/* ---------- Hỏi Google: URL này đã index chưa? (URL Inspection API) ----------
	 * Trả về mảng ['indexed'=>bool, 'state'=>string] hoặc WP_Error.
	 * Quota Google: 2.000 lần/ngày, 600 lần/phút cho mỗi property.
	 */
	public function inspect_url($url) {
		$token = $this->get_access_token(self::SCOPE_GSC);
		if (is_wp_error($token)) return $token;

		$site = get_option(self::OPT_SITE_URL);
		if (!$site) $site = home_url('/');

		$resp = $this->post_retry(self::INSPECT, [
			'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
			'body'    => wp_json_encode([
				'inspectionUrl' => $url,
				'siteUrl'       => $site,
				'languageCode'  => 'vi',
			]),
		]);
		if (is_wp_error($resp)) return $resp;

		$code = wp_remote_retrieve_response_code($resp);
		$body = json_decode(wp_remote_retrieve_body($resp), true);

		if ($code == 403) {
			return new WP_Error('forbidden', 'Service account CHƯA được cấp quyền trong Search Console (hoặc chưa bật Search Console API). Thêm ' . esc_html(json_decode(get_option(self::OPT_JSON), true)['client_email'] ?? '') . ' làm người dùng của property.');
		}
		if ($code == 429) return new WP_Error('quota', 'Hết quota URL Inspection (2.000/ngày).');
		if ($code != 200) {
			return new WP_Error('http_' . $code, $code . ' ' . substr(wp_remote_retrieve_body($resp), 0, 160));
		}

		$r       = isset($body['inspectionResult']['indexStatusResult']) ? $body['inspectionResult']['indexStatusResult'] : [];
		$verdict = isset($r['verdict']) ? $r['verdict'] : 'VERDICT_UNSPECIFIED';
		$state   = isset($r['coverageState']) ? $r['coverageState'] : $verdict;
		// PASS = Google đang index URL này. Mọi giá trị khác coi như chưa index.
		return ['indexed' => ($verdict === 'PASS'), 'state' => $state];
	}

	/* ---------- Gửi 1 URL ---------- */
	public function submit_url($url, $type = 'URL_UPDATED') {
		$token = $this->get_access_token();
		if (is_wp_error($token)) { $this->log($url, 'ERR', $token->get_error_message()); return $token; }
		$resp = $this->post_retry(self::ENDPOINT, [
			'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
			'body'    => wp_json_encode(['url' => $url, 'type' => $type]),
		]);
		if (is_wp_error($resp)) { $this->log($url, 'ERR', $resp->get_error_message()); return $resp; }
		$code = wp_remote_retrieve_response_code($resp);
		$ok   = ($code == 200);
		$this->log($url, $ok ? 'OK' : 'ERR', $ok ? 'URL_UPDATED' : ($code . ' ' . wp_remote_retrieve_body($resp)));
		return $ok;
	}

	/* ---------- Tự động gửi khi publish / trash ---------- */
	public function on_transition($new, $old, $post) {
		if (wp_is_post_revision($post) || wp_is_post_autosave($post)) return;
		$types = (array) get_option(self::OPT_TYPES, ['page', 'post']);
		if (!in_array($post->post_type, $types, true)) return;
		if (!get_option(self::OPT_JSON)) return; // chưa cấu hình
		if ($new === 'publish') {
			// throttle: 1 lần/URL/giờ để đỡ tốn quota khi sửa liên tục
			$key = 'vgi_sent_' . $post->ID;
			if (get_transient($key)) return;
			set_transient($key, 1, HOUR_IN_SECONDS);
			$this->submit_url(get_permalink($post), 'URL_UPDATED');
		} elseif ($new === 'trash' && $old === 'publish') {
			$url = home_url('/' . preg_replace('/__trashed$/', '', $post->post_name) . '/');
			$this->submit_url($url, 'URL_DELETED');
		}
	}

	/* ---------- Chọn bài để kiểm tra: CHƯA kiểm tra bao giờ trước, rồi tới lâu nhất ---------- */
	private function scan_candidates($limit) {
		$types = (array) get_option(self::OPT_TYPES, ['page', 'post']);
		// Ưu tiên 1: chưa từng kiểm tra (không có meta _vgi_checked)
		$ids = get_posts([
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'meta_query'     => [['key' => self::META_CHECKED, 'compare' => 'NOT EXISTS']],
		]);
		if (count($ids) >= $limit) return $ids;

		// Ưu tiên 2: đã kiểm tra rồi nhưng lâu nhất — và CHỈ lấy bài chưa index
		// (bài đã index thì kiểm lại thưa hơn, đỡ tốn quota)
		$more = get_posts([
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit - count($ids),
			'fields'         => 'ids',
			'orderby'        => 'meta_value_num',
			'meta_key'       => self::META_CHECKED,
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'post__not_in'   => $ids ?: [0],
			'meta_query'     => [
				'relation' => 'AND',
				[['key' => self::META_CHECKED, 'compare' => 'EXISTS']],
				[
					'relation' => 'OR',
					// chưa index -> kiểm lại sau 3 ngày
					[
						'relation' => 'AND',
						['key' => self::META_INDEXED, 'value' => '0'],
						['key' => self::META_CHECKED, 'value' => time() - 3 * DAY_IN_SECONDS, 'compare' => '<', 'type' => 'NUMERIC'],
					],
					// đã index -> chỉ kiểm lại sau 30 ngày
					[
						'relation' => 'AND',
						['key' => self::META_INDEXED, 'value' => '1'],
						['key' => self::META_CHECKED, 'value' => time() - 30 * DAY_IN_SECONDS, 'compare' => '<', 'type' => 'NUMERIC'],
					],
				],
			],
		]);
		return array_merge($ids, $more);
	}

	/* ---------- Quét index hàng ngày: kiểm tra -> bài nào CHƯA index thì gửi ---------- */
	public function daily_scan() {
		if (!get_option(self::OPT_JSON)) return;

		$inspect_max = max(1, min(2000, (int) get_option(self::OPT_INSPECT_MAX, 300)));
		$submit_max  = max(1, min(190,  (int) get_option(self::OPT_SUBMIT_MAX, 150)));
		$deadline    = time() + self::TIME_BUDGET;

		$ids = $this->scan_candidates($inspect_max);
		$stats = ['mode' => 'smart', 'time' => current_time('Y-m-d H:i:s'), 'checked' => 0, 'indexed' => 0, 'not_indexed' => 0, 'sent' => 0, 'err' => 0, 'note' => ''];

		foreach ($ids as $id) {
			if (time() > $deadline) { $stats['note'] = 'Dừng theo ngân sách thời gian (' . self::TIME_BUDGET . 's) — phần còn lại chạy ngày mai.'; break; }

			$url = get_permalink($id);
			$res = $this->inspect_url($url);

			if (is_wp_error($res)) {
				$stats['err']++;
				$this->log($url, 'ERR', 'Inspect: ' . $res->get_error_message());
				// Lỗi quyền/quota thì dừng hẳn, chạy tiếp chỉ tốn thời gian
				if (in_array($res->get_error_code(), ['forbidden', 'quota', 'no_json', 'bad_json'], true)) {
					$stats['note'] = $res->get_error_message();
					break;
				}
				continue;
			}

			update_post_meta($id, self::META_CHECKED, time());
			update_post_meta($id, self::META_STATE, $res['state']);
			update_post_meta($id, self::META_INDEXED, $res['indexed'] ? '1' : '0');
			$stats['checked']++;

			if ($res['indexed']) { $stats['indexed']++; continue; }

			$stats['not_indexed']++;
			if ($stats['sent'] >= $submit_max) continue; // hết hạn mức gửi trong ngày

			$ok = $this->submit_url($url, 'URL_UPDATED');
			if ($ok === true) { $stats['sent']++; update_post_meta($id, self::META_SENT, time()); }
			else { $stats['err']++; }
		}

		update_option(self::OPT_STATS, $stats, false);
		$this->log(home_url('/'), $stats['err'] ? 'ERR' : 'OK', sprintf(
			'Quét ngày: kiểm %d (đã index %d, chưa index %d) — đã gửi %d, lỗi %d. %s',
			$stats['checked'], $stats['indexed'], $stats['not_indexed'], $stats['sent'], $stats['err'], $stats['note']
		));
	}

	/* ---------- Chọn bài để GỬI THẲNG: chưa gửi bao giờ trước, rồi tới gửi lâu nhất ----------
	 * Không hỏi Search Console. Bài đã gửi trong vòng $resubmit_days ngày thì bỏ qua
	 * để khỏi gửi lại URL không đổi mỗi ngày (đỡ tốn quota Indexing 200/ngày).
	 */
	private function submit_candidates($limit, $resubmit_days) {
		$types = (array) get_option(self::OPT_TYPES, ['page', 'post']);

		// Ưu tiên 1: chưa từng gửi (không có meta _vgi_sent) — bài mới nhất trước.
		$ids = get_posts([
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'meta_query'     => [['key' => self::META_SENT, 'compare' => 'NOT EXISTS']],
		]);
		if (count($ids) >= $limit) return $ids;

		// Ưu tiên 2: đã gửi rồi nhưng lâu nhất VÀ quá hạn gửi lại ($resubmit_days ngày).
		$more = get_posts([
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit - count($ids),
			'fields'         => 'ids',
			'orderby'        => 'meta_value_num',
			'meta_key'       => self::META_SENT,
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'post__not_in'   => $ids ?: [0],
			'meta_query'     => [[
				'key'     => self::META_SENT,
				'value'   => time() - $resubmit_days * DAY_IN_SECONDS,
				'compare' => '<',
				'type'    => 'NUMERIC',
			]],
		]);
		return array_merge($ids, $more);
	}

	/* ---------- GỬI THẲNG hàng ngày: gửi bài mới nhất lên Indexing API (KHÔNG hỏi GSC) ---------- */
	public function daily_direct_submit() {
		if (!get_option(self::OPT_JSON)) return;

		$submit_max    = max(1, min(190, (int) get_option(self::OPT_SUBMIT_MAX, 150)));
		$resubmit_days = max(1, (int) get_option(self::OPT_RESUBMIT_DAYS, 10));
		$deadline      = time() + self::TIME_BUDGET;

		$ids = $this->submit_candidates($submit_max, $resubmit_days);
		$stats = ['mode' => 'direct', 'time' => current_time('Y-m-d H:i:s'), 'checked' => 0, 'indexed' => 0, 'not_indexed' => 0, 'sent' => 0, 'err' => 0, 'note' => ''];

		foreach ($ids as $id) {
			if (time() > $deadline) { $stats['note'] = 'Dừng theo ngân sách thời gian (' . self::TIME_BUDGET . 's) — phần còn lại chạy ngày mai.'; break; }

			$url = get_permalink($id);
			$res = $this->submit_url($url, 'URL_UPDATED');

			if ($res === true) {
				$stats['sent']++;
				update_post_meta($id, self::META_SENT, time());
			} else {
				$stats['err']++;
				// Lỗi xác thực/cấu hình thì dừng hẳn, chạy tiếp chỉ tốn thời gian.
				if (is_wp_error($res) && in_array($res->get_error_code(), ['no_json', 'bad_json', 'sign'], true)) {
					$stats['note'] = $res->get_error_message();
					break;
				}
			}
		}

		update_option(self::OPT_STATS, $stats, false);
		$this->log(home_url('/'), $stats['err'] ? 'ERR' : 'OK', sprintf(
			'Gửi thẳng ngày: đã gửi %d, lỗi %d. %s',
			$stats['sent'], $stats['err'], $stats['note']
		));
	}

	/* ---------- Log (giữ 100 dòng cuối) ---------- */
	private function log($url, $status, $msg) {
		$log = (array) get_option(self::OPT_LOG, []);
		array_unshift($log, [
			'time'   => current_time('Y-m-d H:i:s'),
			'url'    => $url,
			'status' => $status,
			'msg'    => mb_substr((string) $msg, 0, 200),
		]);
		update_option(self::OPT_LOG, array_slice($log, 0, 100), false);
	}

	/* ---------- Admin ---------- */
	public function menu() {
		add_management_page('Google Indexing', 'Google Indexing', 'manage_options', 'vgi', [$this, 'page']);
	}

	private function url($action) {
		return wp_nonce_url(admin_url('admin-post.php?action=' . $action), $action);
	}

	public function handle_save() {
		if (!current_user_can('manage_options')) wp_die('no');
		check_admin_referer('vgi_save');
		update_option(self::OPT_JSON, trim(wp_unslash($_POST['json'] ?? '')), false);
		$types = array_map('sanitize_text_field', (array) ($_POST['types'] ?? []));
		update_option(self::OPT_TYPES, $types ?: ['page', 'post'], false);

		// Cấu hình chạy hàng ngày
		update_option(self::OPT_CRON_ON, empty($_POST['cron_on']) ? '0' : '1', false);
		$mode = ($_POST['daily_mode'] ?? 'smart') === 'direct' ? 'direct' : 'smart';
		update_option(self::OPT_DAILY_MODE, $mode, false);
		$site = esc_url_raw(trim(wp_unslash($_POST['site_url'] ?? '')));
		update_option(self::OPT_SITE_URL, $site ?: home_url('/'), false);
		update_option(self::OPT_INSPECT_MAX, max(1, min(2000, (int) ($_POST['inspect_max'] ?? 300))), false);
		update_option(self::OPT_SUBMIT_MAX,  max(1, min(190,  (int) ($_POST['submit_max']  ?? 150))), false);
		update_option(self::OPT_RESUBMIT_DAYS, max(1, min(365, (int) ($_POST['resubmit_days'] ?? 10))), false);
		self::sync_schedule();

		delete_transient('vgi_token'); // key cũ (bản 1.0)
		delete_transient('vgi_token_' . md5(self::SCOPE));
		delete_transient('vgi_token_' . md5(self::SCOPE_GSC));
		wp_redirect(admin_url('tools.php?page=vgi&saved=1'));
		exit;
	}

	/* ---------- Chạy quét ngay (thủ công) ---------- */
	public function handle_scan_now() {
		if (!current_user_can('manage_options')) wp_die('no');
		check_admin_referer('vgi_scan_now');
		$this->run_daily(); // chạy đúng kiểu đang chọn (smart / direct)
		wp_redirect(admin_url('tools.php?page=vgi&scanned=1'));
		exit;
	}

	public function handle_test() {
		if (!current_user_can('manage_options')) wp_die('no');
		check_admin_referer('vgi_test');
		$t = $this->get_access_token();
		$ok = !is_wp_error($t);
		wp_redirect(admin_url('tools.php?page=vgi&test=' . ($ok ? 'ok' : urlencode($t->get_error_message()))));
		exit;
	}

	public function handle_bulk() {
		if (!current_user_can('manage_options')) wp_die('no');
		check_admin_referer('vgi_bulk');
		$what  = sanitize_text_field($_POST['what'] ?? 'page');
		$limit = min(190, max(1, (int) ($_POST['limit'] ?? 50)));
		$ids = get_posts([
			'post_type'      => $what,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);
		$ok = 0; $err = 0;
		foreach ($ids as $id) {
			$r = $this->submit_url(get_permalink($id), 'URL_UPDATED');
			if ($r === true) $ok++; else $err++;
		}
		wp_redirect(admin_url("tools.php?page=vgi&bulk={$ok}_{$err}"));
		exit;
	}

	public function page() {
		$json  = get_option(self::OPT_JSON);
		$types = (array) get_option(self::OPT_TYPES, ['page', 'post']);
		$log   = (array) get_option(self::OPT_LOG, []);
		$sa    = $json ? json_decode($json, true) : null;
		echo '<div class="wrap"><h1>Vinasite Google Indexing</h1>';

		if (isset($_GET['saved']))  echo '<div class="notice notice-success"><p>Đã lưu.</p></div>';
		if (isset($_GET['test']))   echo '<div class="notice notice-' . ($_GET['test']==='ok'?'success':'error') . '"><p>Test kết nối: ' . esc_html($_GET['test']==='ok'?'THÀNH CÔNG (lấy được access token)':$_GET['test']) . '</p></div>';
		if (isset($_GET['bulk']))   { list($o,$e)=array_pad(explode('_',$_GET['bulk']),2,0); echo '<div class="notice notice-success"><p>Bulk xong: '.intval($o).' OK, '.intval($e).' lỗi.</p></div>'; }
		if (isset($_GET['scanned'])) echo '<div class="notice notice-success"><p>Đã chạy quét xong — xem kết quả ở mục 3.</p></div>';

		echo '<p><b>Trạng thái:</b> ' . ($sa ? ('Đã cấu hình service account: <code>' . esc_html($sa['client_email'] ?? '?') . '</code>') : '<span style="color:#b32d2e">Chưa có service account JSON</span>') . '</p>';

		// Form lưu JSON + post types
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="vgi_save">';
		wp_nonce_field('vgi_save');
		echo '<h2>1. Service account JSON</h2><p>Dán toàn bộ nội dung file JSON tải từ Google Cloud:</p>';
		echo '<textarea name="json" rows="8" style="width:100%;font-family:monospace" placeholder=\'{"type":"service_account", ...}\'>' . esc_textarea($json) . '</textarea>';
		echo '<h2>2. Tự động gửi index khi publish</h2>';
		foreach (['page'=>'Trang (page)','post'=>'Bài viết (post)','recruit'=>'Tuyển dụng (recruit)','product'=>'Sản phẩm (product)','kho_mau'=>'Mẫu (kho_mau)'] as $pt=>$lbl) {
			echo '<label style="margin-right:16px"><input type="checkbox" name="types[]" value="' . esc_attr($pt) . '" ' . checked(in_array($pt,$types,true), true, false) . '> ' . esc_html($lbl) . '</label>';
		}
		// --- Chạy tự động hàng ngày ---
		$cron_on   = get_option(self::OPT_CRON_ON) === '1';
		$mode      = get_option(self::OPT_DAILY_MODE, 'smart');
		$site_url  = get_option(self::OPT_SITE_URL) ?: home_url('/');
		$imax      = (int) get_option(self::OPT_INSPECT_MAX, 300);
		$smax      = (int) get_option(self::OPT_SUBMIT_MAX, 150);
		$resub     = (int) get_option(self::OPT_RESUBMIT_DAYS, 10);
		echo '<h2>3. Tự động gửi index hàng ngày</h2>';
		echo '<label><input type="checkbox" name="cron_on" value="1" ' . checked($cron_on, true, false) . '> <b>Bật chạy tự động hàng ngày (3:00 sáng)</b></label>';

		echo '<table class="form-table">';
		echo '<tr><th>Kiểu gửi</th><td>';
		echo '<label style="display:block;margin-bottom:6px"><input type="radio" name="daily_mode" value="direct" ' . checked($mode, 'direct', false) . '> <b>Gửi thẳng</b> — gửi bài mới nhất thẳng lên Indexing API. <span class="description">Chạy được ngay, chỉ cần quyền Indexing API. Không cần Search Console.</span></label>';
		echo '<label style="display:block"><input type="radio" name="daily_mode" value="smart" ' . checked($mode, 'smart', false) . '> <b>Thông minh</b> — hỏi Google “đã index chưa?” rồi chỉ gửi bài CHƯA index. <span class="description">Tiết kiệm quota nhưng CẦN cấp quyền Search Console cho service account.</span></label>';
		echo '</td></tr>';
		echo '<tr><th>Số URL gửi index/ngày</th><td><input type="number" name="submit_max" value="' . esc_attr($smax) . '" min="1" max="190" style="width:90px"> <span class="description">Quota Google: 200/ngày</span></td></tr>';
		echo '<tr class="vgi-direct-only"><th>Gửi lại 1 URL sau</th><td><input type="number" name="resubmit_days" value="' . esc_attr($resub) . '" min="1" max="365" style="width:90px"> ngày <span class="description">(chế độ Gửi thẳng) Bài đã gửi trong khoảng này sẽ không gửi lại, ưu tiên bài mới/chưa gửi.</span></td></tr>';
		echo '<tr class="vgi-smart-only"><th>Property trong Search Console</th><td><input type="url" name="site_url" value="' . esc_attr($site_url) . '" style="width:420px"><p class="description">(chế độ Thông minh) Phải khớp CHÍNH XÁC property trong GSC (vd <code>https://vanphongluatsu.com.vn/</code>). Domain property thì dùng <code>sc-domain:vanphongluatsu.com.vn</code>.</p></td></tr>';
		echo '<tr class="vgi-smart-only"><th>Số URL kiểm tra/ngày</th><td><input type="number" name="inspect_max" value="' . esc_attr($imax) . '" min="1" max="2000" style="width:90px"> <span class="description">Quota Google: 2.000/ngày</span></td></tr>';
		echo '</table>';

		// Ẩn/hiện các dòng theo kiểu đang chọn.
		echo '<script>(function(){function upd(){var m=document.querySelector("input[name=daily_mode]:checked");if(!m)return;var d=m.value==="direct";document.querySelectorAll(".vgi-direct-only").forEach(function(e){e.style.display=d?"":"none"});document.querySelectorAll(".vgi-smart-only").forEach(function(e){e.style.display=d?"none":""})}document.querySelectorAll("input[name=daily_mode]").forEach(function(r){r.addEventListener("change",upd)});upd();})();</script>';

		echo '<p><button class="button button-primary">Lưu cấu hình</button></p></form>';

		// --- Trạng thái quét ---
		$stats = (array) get_option(self::OPT_STATS, []);
		$next  = wp_next_scheduled(self::CRON_HOOK);
		echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 18px;margin:10px 0">';
		echo '<b>Kiểu đang chọn:</b> ' . ($mode === 'direct' ? 'Gửi thẳng' : 'Thông minh (hỏi Search Console)') . '<br>';
		echo '<b>Lịch chạy kế tiếp:</b> ' . ($next ? esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'd/m/Y H:i')) : '<span style="color:#b32d2e">chưa bật</span>');
		if ($stats) {
			if (($stats['mode'] ?? 'smart') === 'direct') {
				echo '<br><b>Lần chạy gần nhất</b> (' . esc_html($stats['time']) . '): đã gửi <b>' . intval($stats['sent']) . '</b> URL, lỗi ' . intval($stats['err']);
			} else {
				echo '<br><b>Lần chạy gần nhất</b> (' . esc_html($stats['time']) . '): kiểm <b>' . intval($stats['checked']) . '</b> URL → đã index <b style="color:#1a7f37">' . intval($stats['indexed']) . '</b>, chưa index <b style="color:#b32d2e">' . intval($stats['not_indexed']) . '</b> → đã gửi <b>' . intval($stats['sent']) . '</b>, lỗi ' . intval($stats['err']);
			}
			if (!empty($stats['note'])) echo '<br><i>' . esc_html($stats['note']) . '</i>';
		}
		// Tiến độ toàn site
		global $wpdb;
		if ($mode === 'direct') {
			$sent_cnt = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key=%s", self::META_SENT));
			echo '<br><b>Tiến độ:</b> đã gửi index cho ' . $sent_cnt . ' bài.';
		} else {
			$done = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key=%s", self::META_CHECKED));
			$noidx = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key=%s AND meta_value='0'", self::META_INDEXED));
			echo '<br><b>Tiến độ:</b> đã kiểm tra ' . $done . ' bài — trong đó <b style="color:#b32d2e">' . $noidx . '</b> bài Google báo CHƯA index.';
		}
		echo '</div>';

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:8px"><input type="hidden" name="action" value="vgi_scan_now">';
		wp_nonce_field('vgi_scan_now');
		echo '<button class="button">Chạy ngay 1 lượt (thử)</button> <span class="description">Chạy đúng kiểu đang chọn, tối đa ' . self::TIME_BUDGET . ' giây rồi dừng.</span></form>';

		// Test
		echo '<hr><h2>4. Test kết nối</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="vgi_test">';
		wp_nonce_field('vgi_test');
		echo '<button class="button">Test lấy access token</button></form>';

		// Bulk
		echo '<hr><h2>5. Gửi index hàng loạt</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="vgi_bulk">';
		wp_nonce_field('vgi_bulk');
		echo 'Gửi <select name="what"><option value="page">Trang (page)</option><option value="post">Bài viết (post)</option><option value="recruit">Tuyển dụng</option></select> ';
		echo 'mới nhất, số lượng <input type="number" name="limit" value="50" min="1" max="190" style="width:70px"> (tối đa 190/lần — quota Google 200/ngày) ';
		echo '<button class="button button-primary">Gửi ngay</button></form>';

		// Log
		echo '<hr><h2>Nhật ký (100 gần nhất)</h2><table class="widefat striped"><thead><tr><th>Thời gian</th><th>Trạng thái</th><th>URL</th><th>Chi tiết</th></tr></thead><tbody>';
		foreach ($log as $r) {
			$c = $r['status']==='OK' ? '#1a7f37' : '#b32d2e';
			echo '<tr><td>' . esc_html($r['time']) . '</td><td style="color:' . $c . ';font-weight:600">' . esc_html($r['status']) . '</td><td>' . esc_html($r['url']) . '</td><td><code>' . esc_html($r['msg']) . '</code></td></tr>';
		}
		if (!$log) echo '<tr><td colspan="4">Chưa có log.</td></tr>';
		echo '</tbody></table></div>';
	}
}
new Vinasite_Google_Indexing();

/* Kích hoạt: dựng lịch nếu đã bật. Vô hiệu hoá: gỡ lịch để không còn cron mồ côi. */
register_activation_hook(__FILE__, ['Vinasite_Google_Indexing', 'sync_schedule']);
register_deactivation_hook(__FILE__, function () {
	$next = wp_next_scheduled(Vinasite_Google_Indexing::CRON_HOOK);
	if ($next) wp_unschedule_event($next, Vinasite_Google_Indexing::CRON_HOOK);
});
