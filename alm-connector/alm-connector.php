<?php
/**
 * Plugin Name: 広告リンク管理 コネクタ (ALM Connector)
 * Description: ad-link-manager 本体を一切変更せず、外部アプリ（順位管理ツール）から案件のコメントアウト(disabled)を読み書きするためのREST APIだけを追加する。データは本体と同じ投稿メタ _alm_ad_campaigns / オプション alm_bt_map を共有する。
 * Version: 1.12.1
 * Update URI: https://github.com/terasakisd/alm-plugins
 * Author: -
 *
 * 認証（どちらでも可）:
 *   (A) 共有トークン方式（推奨・ファイル編集不要）:
 *       有効化すると管理画面「設定 → ALM コネクタ」にトークンが自動生成・表示される。
 *       その値をリクエストヘッダ  X-ALM-Token: <トークン>  に載せて送る（HTTPS必須）。
 *       （上級者向け: wp-config.php に define('ALM_CONNECTOR_TOKEN', '...') を定義すると、そちらが優先される）
 *   (B) Application Passwords（Basic認証）:
 *       Authorization: Basic base64("ユーザー名:アプリパスワード")
 *       書き込みは対象投稿に対する edit_post 権限が必要。
 *
 * 【設計メモ】案件名は作り方で揺れる（JS取込=URL末尾のromaji / 記事内リンク取込=1バケツ / 手動=自由）。
 * そのため照合キーは name 単独に頼らず、bt→URL対応表(alm_bt_map)から導く「ブランド」でも照合できるようにする。
 */

if (!defined('ABSPATH')) exit;

class ALM_Connector {

    /** ad-link-manager 本体と必ず一致させること */
    const META_KEY      = '_alm_ad_campaigns'; // 本体: Ad_Link_Manager::META_KEY
    const BT_MAP_OPTION = 'alm_bt_map';        // 本体: Ad_Link_Manager::BT_MAP_OPTION（bt => URL）
    const NS            = 'alm/v1';
    const POST_TYPES    = ['post', 'page'];
    const TOKEN_HEADER  = 'X-ALM-Token';       // 共有トークンを載せるリクエストヘッダ
    const TOKEN_OPTION  = 'alm_connector_token'; // 管理画面で生成・保存する共有トークン
    const NONCE_KEY     = 'alm_connector_nonce';
    const RANK_META     = '_alm_rank_order';     // 順位（キー配列）を保存する投稿メタ
    const RANK_HIDDEN   = '_alm_rank_hidden';    // 順位から除外（セクションごと非表示）するキー配列

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);

        // 管理画面：トークンの自動生成・表示・再生成（wp-config編集を不要にする）
        add_action('admin_menu',  [$this, 'add_settings_page']);
        add_action('admin_init',  [$this, 'ensure_token']); // 未生成なら自動生成（mu-plugins設置でも動く）
        add_action('admin_post_alm_regen_token', [$this, 'handle_regen_token']);
        register_activation_hook(__FILE__, [$this, 'ensure_token']);

        // 順位の自動入れ替え：配信時に h3#list_ ブロックを保存順に並べ替える（DB本文は無改変）
        add_filter('the_content', [$this, 'reorder_content'], 99);
    }

    /* ---------- トークン設定画面 ---------- */

    /** トークン未設定なら自動生成して保存（定数が定義済みのサイトでは何もしない） */
    public function ensure_token() {
        if (defined('ALM_CONNECTOR_TOKEN') && ALM_CONNECTOR_TOKEN) return;
        if (get_option(self::TOKEN_OPTION, '') === '') {
            update_option(self::TOKEN_OPTION, $this->gen_token(), false);
        }
    }

    private function gen_token() {
        if (function_exists('random_bytes')) {
            try { return bin2hex(random_bytes(32)); } catch (\Throwable $e) {}
        }
        return wp_generate_password(64, false, false);
    }

    public function add_settings_page() {
        add_options_page('ALM コネクタ', 'ALM コネクタ', 'manage_options', 'alm-connector', [$this, 'render_settings']);
    }

    public function render_settings() {
        if (!current_user_can('manage_options')) return;
        $by_const = defined('ALM_CONNECTOR_TOKEN') && ALM_CONNECTOR_TOKEN;
        $token    = $by_const ? (string) ALM_CONNECTOR_TOKEN : (string) get_option(self::TOKEN_OPTION, '');
        $rest     = esc_url(rest_url(self::NS . '/article'));
        ?>
        <div class="wrap">
            <h1>ALM コネクタ 設定</h1>
            <p>外部アプリ（案件コメントアウト管理ツール）から接続するための共有トークンです。アプリ側の <code>data/sites.json</code> の <code>token</code> に、この値をそのまま貼り付けてください。</p>

            <table class="form-table">
                <tr>
                    <th scope="row">共有トークン</th>
                    <td>
                        <input type="text" readonly onclick="this.select()" value="<?php echo esc_attr($token); ?>"
                               class="regular-text" style="width:100%; max-width:560px; font-family:monospace">
                        <p class="description">
                            ヘッダ <code><?php echo esc_html(self::TOKEN_HEADER); ?></code> にこの値を載せて送ります（HTTPS必須）。
                        </p>
                        <?php if ($by_const): ?>
                            <p class="description" style="color:#996800">
                                現在は <code>wp-config.php</code> の <code>ALM_CONNECTOR_TOKEN</code> が優先されています（この画面からは変更できません）。
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">接続確認URL</th>
                    <td><code><?php echo $rest; ?>?url=記事URL</code></td>
                </tr>
            </table>

            <?php if (!$by_const): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  onsubmit="return confirm('トークンを再生成します。現在の値は無効になり、アプリ側の設定も更新が必要です。よろしいですか？');">
                <input type="hidden" name="action" value="alm_regen_token">
                <?php wp_nonce_field(self::NONCE_KEY); ?>
                <?php submit_button('トークンを再生成', 'secondary'); ?>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_regen_token() {
        if (!current_user_can('manage_options')) wp_die('権限がありません');
        check_admin_referer(self::NONCE_KEY);
        update_option(self::TOKEN_OPTION, $this->gen_token(), false);
        wp_safe_redirect(admin_url('options-general.php?page=alm-connector&regenerated=1'));
        exit;
    }

    public function register_routes() {
        // 記事の案件一覧（name・disabled・bt・導出ブランド）を返す＝アプリのトピック取り込み用
        register_rest_route(self::NS, '/article', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_article'],
            'permission_callback' => [$this, 'can_read'],
            'args'                => [
                'url'     => ['type' => 'string',  'required' => false],
                'post_id' => ['type' => 'integer', 'required' => false],
            ],
        ]);

        // 1案件のコメントアウト切替（name か brand のどちらかで指定）
        register_rest_route(self::NS, '/comment-out', [
            'methods'             => 'POST',
            'callback'            => [$this, 'set_comment_out'],
            'permission_callback' => [$this, 'can_write'],
            'args'                => [
                'url'      => ['type' => 'string',  'required' => false],
                'post_id'  => ['type' => 'integer', 'required' => false],
                'name'     => ['type' => 'string',  'required' => false],
                'brand'    => ['type' => 'string',  'required' => false],
                'disabled' => ['type' => 'boolean', 'required' => true],
            ],
        ]);

        // 記事まるごと一括反映（アプリの「反映」ボタン用）
        register_rest_route(self::NS, '/article/sync', [
            'methods'             => 'POST',
            'callback'            => [$this, 'sync_article'],
            'permission_callback' => [$this, 'can_write'],
            'args'                => [
                'url'     => ['type' => 'string',  'required' => false],
                'post_id' => ['type' => 'integer', 'required' => false],
                'items'   => ['type' => 'array',   'required' => true], // [{name|brand, disabled}, ...]
            ],
        ]);

        // 案件を削除（_alm_ad_campaigns から取り除く）
        register_rest_route(self::NS, '/campaign/delete', [
            'methods'             => 'POST',
            'callback'            => [$this, 'delete_campaign'],
            'permission_callback' => [$this, 'can_write'],
            'args'                => [
                'url'     => ['type' => 'string',  'required' => false],
                'post_id' => ['type' => 'integer', 'required' => false],
                'name'    => ['type' => 'string',  'required' => false],
                'brand'   => ['type' => 'string',  'required' => false],
            ],
        ]);

        // 順位：現在の並びを取得（アプリのドラッグUI用）
        register_rest_route(self::NS, '/ranking', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_ranking'],
            'permission_callback' => [$this, 'can_read'],
            'args'                => [
                'url'     => ['type' => 'string',  'required' => false],
                'post_id' => ['type' => 'integer', 'required' => false],
            ],
        ]);

        // 順位：新しい並びを保存（配信時に適用される）
        register_rest_route(self::NS, '/ranking', [
            'methods'             => 'POST',
            'callback'            => [$this, 'set_ranking'],
            'permission_callback' => [$this, 'can_write'],
            'args'                => [
                'url'     => ['type' => 'string',  'required' => false],
                'post_id' => ['type' => 'integer', 'required' => false],
                'order'   => ['type' => 'array',   'required' => true],  // 表示する順（['acom','mobit',...]）
                'hidden'  => ['type' => 'array',   'required' => false], // 順位から除外＝非表示にするキー
            ],
        ]);

        // 詳細設定：案件フル（values含む）を取得＝プラグインのメタボックスと同じデータ
        register_rest_route(self::NS, '/campaigns', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_campaigns_full'],
            'permission_callback' => [$this, 'can_read'],
            'args'                => [
                'url'     => ['type' => 'string',  'required' => false],
                'post_id' => ['type' => 'integer', 'required' => false],
            ],
        ]);

        // 詳細設定：案件フルを保存（プラグイン設定と同期）
        register_rest_route(self::NS, '/campaigns', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save_campaigns_full'],
            'permission_callback' => [$this, 'can_write'],
            'args'                => [
                'url'       => ['type' => 'string',  'required' => false],
                'post_id'   => ['type' => 'integer', 'required' => false],
                'campaigns' => ['type' => 'array',   'required' => true],
            ],
        ]);

        // 詳細設定：記事内リンク一括取得（プラグインのスキャン機能を中継）
        register_rest_route(self::NS, '/scan-links', [
            'methods'             => 'GET',
            'callback'            => [$this, 'scan_links'],
            'permission_callback' => [$this, 'can_read'],
            'args'                => [
                'url'     => ['type' => 'string',  'required' => false],
                'post_id' => ['type' => 'integer', 'required' => false],
            ],
        ]);
    }

    /* ---------- 権限 ---------- */

    public function can_read(WP_REST_Request $req) {
        if ($this->token_ok($req)) return true;       // (A) 共有トークン
        return current_user_can('edit_posts');        // (B) アプリパスワード等でログイン済み
    }

    public function can_write(WP_REST_Request $req) {
        if ($this->token_ok($req)) return true;       // (A) 共有トークンなら権限チェック省略
        $post_id = $this->resolve_post_id($req);
        if (!$post_id) return true; // 投稿解決失敗は callback 側で 404
        return current_user_can('edit_post', $post_id);
    }

    /** 共有トークン。優先順: wp-config定数 > 管理画面で保存したオプション > フィルタ */
    private function configured_token() {
        if (defined('ALM_CONNECTOR_TOKEN') && ALM_CONNECTOR_TOKEN) {
            return (string) ALM_CONNECTOR_TOKEN;
        }
        $opt = (string) get_option(self::TOKEN_OPTION, '');
        if ($opt !== '') return $opt;
        return (string) apply_filters('alm_connector_token', '');
    }

    /** リクエストの X-ALM-Token が設定済みトークンと一致するか（定数時間比較） */
    private function token_ok(WP_REST_Request $req) {
        $configured = $this->configured_token();
        if ($configured === '') return false;          // 未設定ならトークン認証は無効
        $provided = (string) $req->get_header(self::TOKEN_HEADER);
        if ($provided === '') return false;
        return hash_equals($configured, $provided);
    }

    /* ---------- 投稿の特定 ---------- */

    private function resolve_post_id(WP_REST_Request $req) {
        $post_id = (int) $req->get_param('post_id');
        if ($post_id > 0) {
            return $this->valid_post_id($post_id);
        }
        $url = (string) $req->get_param('url');
        if ($url === '') return 0;

        // 1) 公開記事は url_to_postid で解決
        foreach ([$url, untrailingslashit($url), trailingslashit($url)] as $u) {
            $id = url_to_postid($u);
            if ($id && ($v = $this->valid_post_id($id))) return $v;
        }

        // 2) ?p=123 / ?page_id=123 / ?post=123 形式（プレビューURL等）
        $query = wp_parse_url($url, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $args);
            foreach (['p', 'page_id', 'post'] as $k) {
                if (!empty($args[$k]) && is_numeric($args[$k])) {
                    if ($v = $this->valid_post_id((int) $args[$k])) return $v;
                }
            }
        }

        // 3) 下書き・非公開・予約投稿も対象に、URL末尾スラッグで全ステータス検索
        $slug = $this->slug_from_url($url);
        if ($slug !== '') {
            $found = get_posts([
                'name'        => $slug,
                'post_type'   => self::POST_TYPES,
                'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
                'numberposts' => 1,
                'fields'      => 'ids',
            ]);
            if (!empty($found)) return (int) $found[0];
        }

        return 0;
    }

    /** 対象post_typeの実在する投稿IDなら返す。違えば0 */
    private function valid_post_id($id) {
        $p = get_post((int) $id);
        return ($p && in_array($p->post_type, self::POST_TYPES, true)) ? (int) $p->ID : 0;
    }

    /** URLの末尾セグメントを投稿スラッグ(post_name)として取り出す */
    private function slug_from_url($url) {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $path = trim($path, '/');
        if ($path === '') return '';
        $segments = explode('/', $path);
        $last = end($segments);
        return rawurldecode($last); // 日本語スラッグにも対応
    }

    /* ---------- 取得 ---------- */

    public function get_article(WP_REST_Request $req) {
        $post_id = $this->resolve_post_id($req);
        if (!$post_id) {
            return new WP_Error('alm_not_found', 'URL/IDから投稿を特定できませんでした', ['status' => 404]);
        }
        $campaigns = $this->read_campaigns($post_id);
        $bt_map    = $this->bt_map_lower();

        $out = [];
        foreach ($campaigns as $i => $c) {
            $bts = $this->extract_bts($c);
            $out[] = [
                'index'    => $i,
                'name'     => isset($c['name']) ? (string) $c['name'] : '',
                'disabled' => !empty($c['disabled']),
                'bts'      => $bts,
                'brands'   => $this->brands_for_bts($bts, $bt_map), // bt→URLから導いた安定キー
            ];
        }
        return new WP_REST_Response([
            'post_id'    => $post_id,
            'permalink'  => get_permalink($post_id),
            'title'      => get_the_title($post_id),
            'campaigns'  => $out,
            'bt_map_set' => !empty($bt_map), // 対応表が未生成ならブランド照合は使えない
        ], 200);
    }

    /* ---------- 1件切替 ---------- */

    public function set_comment_out(WP_REST_Request $req) {
        $post_id = $this->resolve_post_id($req);
        if (!$post_id) {
            return new WP_Error('alm_not_found', 'URL/IDから投稿を特定できませんでした', ['status' => 404]);
        }
        $name     = trim((string) $req->get_param('name'));
        $brand    = trim((string) $req->get_param('brand'));
        $disabled = (bool) $req->get_param('disabled');
        if ($name === '' && $brand === '') {
            return new WP_Error('alm_bad_request', 'name か brand のどちらかは必須です', ['status' => 400]);
        }

        $campaigns = $this->read_campaigns($post_id);
        $bt_map    = $this->bt_map_lower();
        $hit = 0;
        foreach ($campaigns as &$c) {
            if ($this->campaign_matches($c, $name, $brand, $bt_map)) {
                $c['disabled'] = $disabled;
                $hit++;
            }
        }
        unset($c);

        if ($hit === 0) {
            $label = $name !== '' ? $name : $brand;
            return new WP_Error('alm_no_campaign', "案件「{$label}」が見つかりません", ['status' => 404]);
        }

        $this->write_campaigns($post_id, $campaigns);
        return new WP_REST_Response([
            'post_id'  => $post_id,
            'matched'  => $name !== '' ? "name:{$name}" : "brand:{$brand}",
            'disabled' => $disabled,
            'updated'  => $hit,
        ], 200);
    }

    /* ---------- 一括反映 ---------- */

    public function sync_article(WP_REST_Request $req) {
        $post_id = $this->resolve_post_id($req);
        if (!$post_id) {
            return new WP_Error('alm_not_found', 'URL/IDから投稿を特定できませんでした', ['status' => 404]);
        }
        $items = $req->get_param('items');
        if (!is_array($items)) {
            return new WP_Error('alm_bad_request', 'items は配列で渡してください', ['status' => 400]);
        }

        $campaigns = $this->read_campaigns($post_id);
        $bt_map    = $this->bt_map_lower();

        $applied   = [];
        $unmatched = [];

        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $name     = isset($it['name'])  ? trim((string) $it['name'])  : '';
            $brand    = isset($it['brand']) ? trim((string) $it['brand']) : '';
            $disabled = !empty($it['disabled']);
            if ($name === '' && $brand === '') continue;

            $hit = 0;
            foreach ($campaigns as &$c) {
                if ($this->campaign_matches($c, $name, $brand, $bt_map)) {
                    $c['disabled'] = $disabled;
                    $hit++;
                }
            }
            unset($c);

            $label = $name !== '' ? $name : $brand;
            if ($hit > 0) $applied[]   = ['label' => $label, 'disabled' => $disabled, 'updated' => $hit];
            else          $unmatched[] = $label; // アプリ側にあるが記事に無い
        }

        $this->write_campaigns($post_id, $campaigns);
        return new WP_REST_Response([
            'post_id'   => $post_id,
            'applied'   => $applied,
            'unmatched' => $unmatched,
        ], 200);
    }

    /* ---------- 詳細設定（案件フル）：プラグイン設定と同期 ---------- */

    public function get_campaigns_full(WP_REST_Request $req) {
        $post_id = $this->resolve_post_id($req);
        if (!$post_id) {
            return new WP_Error('alm_not_found', 'URL/IDから投稿を特定できませんでした', ['status' => 404]);
        }
        // プラグインが情報を渡す（未更新なら共有メタをそのまま返す）
        $campaigns = apply_filters('alm_connector_get_campaigns', $this->read_campaigns($post_id), $post_id);
        $bt_map    = apply_filters('alm_connector_bt_map', get_option('alm_bt_map', []));
        $match     = apply_filters('alm_connector_match_type', get_option('alm_match_type', 'bt'));
        return new WP_REST_Response([
            'post_id'   => $post_id,
            'title'     => get_the_title($post_id),
            'permalink' => get_permalink($post_id),
            'match'     => $match,
            'bt_map'    => is_array($bt_map) ? $bt_map : [],
            'campaigns' => is_array($campaigns) ? array_values($campaigns) : [],
        ], 200);
    }

    public function save_campaigns_full(WP_REST_Request $req) {
        $post_id = $this->resolve_post_id($req);
        if (!$post_id) {
            return new WP_Error('alm_not_found', 'URL/IDから投稿を特定できませんでした', ['status' => 404]);
        }
        $raw = $req->get_param('campaigns');
        if (!is_array($raw)) {
            return new WP_Error('alm_bad_request', 'campaigns は配列で渡してください', ['status' => 400]);
        }
        // プラグインと同じルールでサニタイズ（未更新ならそのまま保存）
        $clean = apply_filters('alm_connector_clean_campaigns', $raw, $raw);
        if (!is_array($clean)) $clean = $raw;
        $this->write_campaigns($post_id, array_values($clean));
        $saved = apply_filters('alm_connector_get_campaigns', $this->read_campaigns($post_id), $post_id);
        return new WP_REST_Response([
            'post_id'   => $post_id,
            'campaigns' => is_array($saved) ? array_values($saved) : [],
        ], 200);
    }

    public function scan_links(WP_REST_Request $req) {
        $post_id = $this->resolve_post_id($req);
        if (!$post_id) {
            return new WP_Error('alm_not_found', 'URL/IDから投稿を特定できませんでした', ['status' => 404]);
        }
        $data = apply_filters('alm_connector_scan_links', null, $post_id);
        if (!is_array($data)) {
            return new WP_Error('alm_no_plugin', 'プラグインのスキャン機能が利用できません（ad-link-manager の更新が必要です）', ['status' => 501]);
        }
        // スキャン中に本体側で対応表が自動マージされるため、最新の bt対応表を応答に同梱する
        // （ツールはこれを使って取り込みをブランド別に分割する）
        $data['bt_map'] = $this->bt_map_lower();
        return new WP_REST_Response($data, 200);
    }

    /* ---------- 案件の削除 ---------- */

    public function delete_campaign(WP_REST_Request $req) {
        $post_id = $this->resolve_post_id($req);
        if (!$post_id) {
            return new WP_Error('alm_not_found', 'URL/IDから投稿を特定できませんでした', ['status' => 404]);
        }
        $name  = trim((string) $req->get_param('name'));
        $brand = trim((string) $req->get_param('brand'));
        if ($name === '' && $brand === '') {
            return new WP_Error('alm_bad_request', 'name か brand のどちらかは必須です', ['status' => 400]);
        }

        $campaigns = $this->read_campaigns($post_id);
        $bt_map    = $this->bt_map_lower();
        $kept = [];
        $removed = 0;
        foreach ($campaigns as $c) {
            if ($this->campaign_matches($c, $name, $brand, $bt_map)) {
                $removed++;
            } else {
                $kept[] = $c;
            }
        }
        if ($removed === 0) {
            $label = $name !== '' ? $name : $brand;
            return new WP_Error('alm_no_campaign', "案件「{$label}」が見つかりません", ['status' => 404]);
        }

        $this->write_campaigns($post_id, array_values($kept));
        return new WP_REST_Response([
            'post_id'   => $post_id,
            'removed'   => $removed,
            'remaining' => count($kept),
        ], 200);
    }

    /* ---------- 順位 ---------- */

    public function get_ranking(WP_REST_Request $req) {
        $post_id = $this->resolve_post_id($req);
        if (!$post_id) {
            return new WP_Error('alm_not_found', 'URL/IDから投稿を特定できませんでした', ['status' => 404]);
        }
        $content = (string) get_post_field('post_content', $post_id);
        $units   = $this->find_rank_units($content);

        // 管理画面に出すのは「順位の元」＝見出し(h3)のトピックだけ。
        // リスト/テーブル(li/div/tr)は表示せず、配信時に同じ並びへ追従するだけ。
        $items = [];
        $seenKeys = [];
        foreach ($units as $u) {
            if (empty($u['is_source'])) continue;
            if ($u['key'] === '' || isset($seenKeys[$u['key']])) continue;
            $seenKeys[$u['key']] = true;
            $items[] = [
                'key'      => $u['key'],
                'brand'    => $u['brand'],
                'id'       => $u['id'],
                'heading'  => $u['heading'],
                'has_link' => $u['brand'] !== '',
            ];
        }

        // 除外（非表示）フラグを付与
        $hidden = get_post_meta($post_id, self::RANK_HIDDEN, true);
        $hiddenSet = [];
        if (is_array($hidden)) {
            foreach ($hidden as $h) $hiddenSet[strtolower(trim((string) $h))] = true;
        }
        foreach ($items as &$it) $it['hidden'] = isset($hiddenSet[$it['key']]);
        unset($it);

        // 表示分は保存順に、除外分は末尾に
        $saved = get_post_meta($post_id, self::RANK_META, true);
        $has_saved = is_array($saved) && !empty($saved);
        $visible = array_values(array_filter($items, function ($it) { return empty($it['hidden']); }));
        $excluded = array_values(array_filter($items, function ($it) { return !empty($it['hidden']); }));
        if ($has_saved) $visible = $this->sort_items_by_order($visible, $saved);
        $items = array_merge($visible, $excluded);

        return new WP_REST_Response([
            'post_id' => $post_id,
            'title'   => get_the_title($post_id),
            'items'   => $items,
            'saved'   => $has_saved,
        ], 200);
    }

    public function set_ranking(WP_REST_Request $req) {
        $post_id = $this->resolve_post_id($req);
        if (!$post_id) {
            return new WP_Error('alm_not_found', 'URL/IDから投稿を特定できませんでした', ['status' => 404]);
        }
        $order = $req->get_param('order');
        if (!is_array($order)) {
            return new WP_Error('alm_bad_request', 'order（配列）が必要です', ['status' => 400]);
        }
        $clean = [];
        foreach ($order as $k) {
            $k = strtolower(trim((string) $k));
            if ($k !== '') $clean[] = $k;
        }
        update_post_meta($post_id, self::RANK_META, $clean);

        // 除外（順位から消す＝セクションごと非表示）キー
        $hidden = $req->get_param('hidden');
        $cleanHidden = [];
        if (is_array($hidden)) {
            foreach ($hidden as $k) {
                $k = strtolower(trim((string) $k));
                if ($k !== '') $cleanHidden[] = $k;
            }
        }
        update_post_meta($post_id, self::RANK_HIDDEN, $cleanHidden);

        $this->purge_post_cache($post_id);
        return new WP_REST_Response(['post_id' => $post_id, 'order' => $clean, 'hidden' => $cleanHidden], 200);
    }

    /** 配信時フィルタ：保存済みの順序で「id が list で始まる要素」を並べ替える（h3・li・div等の混在対応） */
    public function reorder_content($content) {
        if (is_admin() || !is_singular(self::POST_TYPES)) return $content;
        if (!in_the_loop() || !is_main_query()) return $content;
        $post_id = get_the_ID();
        if (!$post_id) return $content;

        $order  = get_post_meta($post_id, self::RANK_META, true);
        $hidden = get_post_meta($post_id, self::RANK_HIDDEN, true);
        $hasOrder  = is_array($order) && !empty($order);
        $hasHidden = is_array($hidden) && !empty($hidden);
        if (!$hasOrder && !$hasHidden) return $content;

        try {
            $units = $this->find_rank_units($content);
            if (empty($units)) return $content;
            $new = $this->apply_order_inplace($content, $units, $hasOrder ? $order : [], $hasHidden ? $hidden : []);
            // 安全策：異常（極端な長さ変化等）なら元の本文を返す
            if (!is_string($new) || $new === '') return $content;
            return $new;
        } catch (\Throwable $e) {
            return $content; // 何かあっても本文は壊さない
        }
    }

    /**
     * 本文から順位ユニットを抽出する（混在対応）。対象は次のどちらかを持つ要素：
     *   - id="list…"（従来）
     *   - data-rank="…"（新）… アンカー用 id とは別に「並べ替えキー」を指定できる
     * キー優先度：data-rank ＞ /lp/○○.html のブランド ＞ id。
     * data-rank は「トピックの id」または「ブランド名」のどちらでも一致（id↔ブランドを解決）。
     *  - h3: 見出しなので「次の h2/h3 まで」を1ブロック（従来と同じ挙動）
     *  - それ以外（li/div/a/p など）: そのタグの閉じまで（自己完結の outerHTML）
     * 入れ子・重複するユニットは外側だけ残す（本文破壊を防ぐ）。
     */
    private function find_rank_units($content) {
        // id="list…" もしくは data-rank="…" を持つ開始タグを候補として拾う
        $pattern = '/<([a-z][a-z0-9]*)\b(?=[^>]*(?:\bdata-rank\s*=\s*["\']|\bid\s*=\s*["\']list))[^>]*>/i';
        if (!preg_match_all($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        // h3 ユニットの終端算出用に、h2/h3 の位置を集めておく
        $headPositions = [];
        if (preg_match_all('/<h[23]\b[^>]*>/i', $content, $hm, PREG_OFFSET_CAPTURE)) {
            foreach ($hm[0] as $x) $headPositions[] = $x[1];
        }

        $len = strlen($content);
        $units = [];
        $btmap = $this->bt_map_lower(); // /lp/形式が無いサイトのブランド解決用
        $n = count($m[0]);
        for ($i = 0; $i < $n; $i++) {
            $full  = $m[0][$i][0];
            $start = $m[0][$i][1];
            $tag   = strtolower($m[1][$i][0]);
            $isHeading = (bool) preg_match('/^h[1-6]$/', $tag);

            // 従来通り見出しは h3 のみ対象。見出し以外（li/div等）はタグ不問で対象。
            if ($isHeading && $tag !== 'h3') continue;

            // 開始タグから id（list始まり）と data-rank を取り出す
            $id = '';
            if (preg_match('/\bid\s*=\s*["\'](list[^"\']*)["\']/i', $full, $im)) $id = strtolower($im[1]);
            $dataRank = '';
            if (preg_match('/\bdata-rank\s*=\s*["\']([^"\']*)["\']/i', $full, $dm)) $dataRank = strtolower(trim($dm[1]));

            if ($tag === 'h3') {
                $end = $len;
                foreach ($headPositions as $hp) {
                    if ($hp > $start) { $end = $hp; break; }
                }
            } else {
                $end = $this->match_element_end($content, $tag, $start + strlen($full));
                if ($end === null) continue; // 閉じタグが見つからない → 対象外
            }

            $html  = substr($content, $start, $end - $start);
            $brand = '';
            if (preg_match('#/lp/([a-z0-9_\-]+)\.html#i', $html, $bm)) $brand = strtolower($bm[1]);
            // /lp/形式のリンクが無いサイト（bt ID＋JSクリックの構成）は、
            // ユニット内の bt ID を bt対応表（alm_bt_map）で引いて、リンク先URLのファイル名をブランドにする
            if ($brand === '' && !empty($btmap) && preg_match_all('/\bid=["\'](bt\d+)["\']/i', $html, $btm)) {
                foreach ($btm[1] as $bt) {
                    $k = strtolower($bt);
                    if (isset($btmap[$k])) {
                        $b = $this->brand_from_url($btmap[$k]);
                        if ($b !== '') { $brand = strtolower($b); break; }
                    }
                }
            }
            $is_heading = ($tag === 'h3'); // 管理画面に出す「順位の元」= 見出しのみ

            $heading = trim(wp_strip_all_tags($html));
            if (mb_strlen($heading) > 40) $heading = mb_substr($heading, 0, 40) . '…';

            $units[] = compact('tag', 'id', 'dataRank', 'start', 'end', 'html', 'brand', 'heading', 'is_heading');
        }

        // id → ブランド の対応表（トピックから）。data-rank に「トピックの id」を書いてもブランドに解決できる
        $aliasIdToBrand = [];
        foreach ($units as $u) {
            if ($u['brand'] === '') continue;
            if ($u['id'] !== '' && !isset($aliasIdToBrand[$u['id']])) {
                $aliasIdToBrand[$u['id']] = $u['brand'];
            }
            // 表記ゆれ吸収: data-rank="topic_yokohamabank" と ブランド "yokohama-bank" のように
            // 記号の有無だけ違うキーを同じブランドへ解決する
            $norm = preg_replace('/[^a-z0-9]/', '', $u['brand']);
            if ($norm !== '' && $norm !== $u['brand'] && !isset($aliasIdToBrand[$norm])) {
                $aliasIdToBrand[$norm] = $u['brand'];
            }
        }
        // キーと「順位の元(is_source)」を確定する。
        //  - data-rank="topic_◯◯" … 順位の元（管理画面に表示・ドラッグ対象）。キーは ◯◯
        //  - data-rank="◯◯"       … 追従（管理画面には出さず、同じキーの並びに追従）
        //  - data-rank 無し（従来）  … h3#list が順位の元。キーはブランド or id
        // ◯◯ には「トピックの id」も書ける（id↔ブランドを自動解決）。
        foreach ($units as &$u) {
            if ($u['dataRank'] !== '') {
                $isTopic = (strncmp($u['dataRank'], 'topic_', 6) === 0);
                $raw = $isTopic ? substr($u['dataRank'], 6) : $u['dataRank'];
                $u['key'] = $aliasIdToBrand[$raw] ?? $raw;
                $u['is_source'] = $isTopic;
            } elseif ($u['brand'] !== '') {
                $u['key'] = $u['brand'];
                $u['is_source'] = $u['is_heading'];
            } else {
                $u['key'] = $u['id'];
                $u['is_source'] = $u['is_heading'];
            }
        }
        unset($u);

        // 位置順に整列し、入れ子/重複（前のユニットの範囲内で始まるもの）は外側だけ残す
        usort($units, function ($a, $b) { return $a['start'] <=> $b['start']; });
        $result = [];
        $lastEnd = -1;
        foreach ($units as $u) {
            if ($u['start'] < $lastEnd) continue;
            $result[] = $u;
            $lastEnd = $u['end'];
        }
        return $result;
    }

    /** 開始タグ位置 $from から、$tag の対応する閉じタグ直後の位置を返す（入れ子対応）。無ければ null。 */
    private function match_element_end($content, $tag, $from) {
        $depth   = 1;
        $offset  = $from;
        $pattern = '/<(\/?)' . preg_quote($tag, '/') . '\b[^>]*?(\/?)>/i';
        while ($depth > 0 && preg_match($pattern, $content, $mm, PREG_OFFSET_CAPTURE, $offset)) {
            $whole     = $mm[0][0];
            $pos       = $mm[0][1];
            $isClose   = ($mm[1][0] === '/');
            $selfClose = ($mm[2][0] === '/');
            $offset    = $pos + strlen($whole);
            if ($isClose) {
                $depth--;
                if ($depth === 0) return $offset;
            } elseif (!$selfClose) {
                $depth++;
            }
        }
        return null;
    }

    /**
     * 順位ユニットを保存順に並べ替え、hidden は出力から除外して、本文をインプレースで書き換える。
     * 「連続したまとまり(run)」ごとに独立して並べ替えるので、
     * トピック(h3)とリスト(li/div)のように同じブランドが複数箇所にあっても、それぞれが同じ順位に揃う。
     */
    private function apply_order_inplace($content, $units, $order, $hidden) {
        $hiddenSet = [];
        foreach ($hidden as $h) { $h = strtolower(trim((string) $h)); if ($h !== '') $hiddenSet[$h] = true; }

        // 保存順: key => 順位index
        $rank = [];
        $r = 0;
        foreach ($order as $k) {
            $k = strtolower(trim((string) $k));
            if ($k !== '' && !isset($rank[$k])) $rank[$k] = $r++;
        }

        // 連続（間が空白のみ）のユニットを1グループ(run)にまとめる
        $runs = [];
        $cur = [];
        $count = count($units);
        for ($i = 0; $i < $count; $i++) {
            if ($i > 0) {
                $gapStart = $units[$i - 1]['end'];
                $gap = substr($content, $gapStart, $units[$i]['start'] - $gapStart);
                if (trim($gap) !== '') { $runs[] = $cur; $cur = []; }
            }
            $cur[] = $i;
        }
        if (!empty($cur)) $runs[] = $cur;

        // 各 run 内で保存順に並べ替え（hiddenは除外＝末尾スロットを空に）
        $replacements = [];
        foreach ($runs as $run) {
            $visible = array_values(array_filter($run, function ($i) use ($units, $hiddenSet) {
                return !isset($hiddenSet[$units[$i]['key']]);
            }));
            usort($visible, function ($a, $b) use ($units, $rank) {
                $ra = isset($rank[$units[$a]['key']]) ? $rank[$units[$a]['key']] : PHP_INT_MAX;
                $rb = isset($rank[$units[$b]['key']]) ? $rank[$units[$b]['key']] : PHP_INT_MAX;
                if ($ra !== $rb) return $ra <=> $rb;
                return $a <=> $b; // 同順位は元の位置順（安定ソート）
            });
            for ($s = 0; $s < count($run); $s++) {
                $slot = $units[$run[$s]];
                $html = ($s < count($visible)) ? $units[$visible[$s]]['html'] : '';
                $replacements[] = ['start' => $slot['start'], 'len' => $slot['end'] - $slot['start'], 'html' => $html];
            }
        }

        // 後ろから置換してオフセットを維持
        usort($replacements, function ($a, $b) { return $b['start'] <=> $a['start']; });
        foreach ($replacements as $rp) {
            $content = substr_replace($content, $rp['html'], $rp['start'], $rp['len']);
        }
        return $content;
    }

    /**
     * 本文を見出し(h2/h3)で区切ってセグメント化。
     * 各h3に id="list*" が付いていれば順位対象とみなし、ブランド(/lp/○○.html)かidをキーにする。
     */
    private function analyze_segments($content) {
        if (!preg_match_all('/<h([23])\b[^>]*>/i', $content, $m, PREG_OFFSET_CAPTURE)) {
            return ['prefix' => $content, 'segments' => []];
        }
        $heads = $m[0];
        $count = count($heads);
        $prefix = substr($content, 0, $heads[0][1]);

        $segments = [];
        for ($i = 0; $i < $count; $i++) {
            $start = $heads[$i][1];
            $end   = ($i + 1 < $count) ? $heads[$i + 1][1] : strlen($content);
            $html  = substr($content, $start, $end - $start);
            $level = $m[1][$i][0];

            $id = '';
            if (preg_match('/\bid=["\']([^"\']+)["\']/i', $heads[$i][0], $im)) $id = $im[1];

            $isRanked = ($level === '3' && stripos($id, 'list') === 0);

            $brand = '';
            if ($isRanked && preg_match('#/lp/([a-z0-9_\-]+)\.html#i', $html, $bm)) {
                $brand = strtolower($bm[1]);
            }
            $key = $isRanked ? ($brand !== '' ? $brand : strtolower($id)) : '';

            $heading = '';
            if (preg_match('/<h3\b[^>]*>(.*?)<\/h3>/is', $html, $hm)) {
                $heading = trim(wp_strip_all_tags($hm[1]));
            }

            $segments[] = compact('html', 'level', 'id', 'isRanked', 'brand', 'key', 'heading');
        }
        return ['prefix' => $prefix, 'segments' => $segments];
    }

    /**
     * 順位対象セグメントを order の順に並べ替え、hidden のキーは出力から除外して本文を再構成。
     * （対象スロットには表示分を順に詰め、余ったスロットは空にする＝除外分が消える）
     */
    private function apply_order($parsed, $order, $hidden = []) {
        $segments  = $parsed['segments'];
        $rankedIdx = [];
        foreach ($segments as $i => $s) {
            if ($s['isRanked']) $rankedIdx[] = $i;
        }
        if (empty($rankedIdx)) return null;

        $hiddenSet = [];
        foreach ($hidden as $h) {
            $h = strtolower(trim((string) $h));
            if ($h !== '') $hiddenSet[$h] = true;
        }

        $byKey = [];
        foreach ($rankedIdx as $i) {
            $k = $segments[$i]['key'];
            if ($k !== '' && !isset($byKey[$k])) $byKey[$k] = $i;
        }

        // 表示する順（hiddenは除外）
        $seq  = [];
        $used = [];
        foreach ($order as $k) {
            $k = strtolower(trim((string) $k));
            if (isset($hiddenSet[$k])) continue;
            if (isset($byKey[$k]) && !isset($used[$byKey[$k]])) {
                $seq[] = $byKey[$k];
                $used[$byKey[$k]] = true;
            }
        }
        // order に無い対象（hidden以外）は元の順で末尾に
        foreach ($rankedIdx as $i) {
            if (isset($used[$i])) continue;
            if (isset($hiddenSet[$segments[$i]['key']])) continue;
            $seq[] = $i;
            $used[$i] = true;
        }

        // 対象スロットに表示分を順に詰める。余りスロットは空（＝除外分が消える）
        $out = $segments;
        foreach ($rankedIdx as $slot => $slotIdx) {
            $out[$slotIdx] = isset($seq[$slot]) ? $segments[$seq[$slot]] : ['html' => ''];
        }

        $html = $parsed['prefix'];
        foreach ($out as $s) $html .= $s['html'];
        return $html;
    }

    /** items を order（キー配列）の順に並べ替え。order外は元順で末尾。 */
    private function sort_items_by_order($items, $order) {
        $byKey = [];
        foreach ($items as $it) {
            if ($it['key'] !== '' && !isset($byKey[$it['key']])) $byKey[$it['key']] = $it;
        }
        $sorted = [];
        $used   = [];
        foreach ($order as $k) {
            $k = strtolower(trim((string) $k));
            if (isset($byKey[$k]) && !isset($used[$k])) { $sorted[] = $byKey[$k]; $used[$k] = true; }
        }
        foreach ($items as $it) {
            if (!isset($used[$it['key']])) { $sorted[] = $it; $used[$it['key']] = true; }
        }
        return $sorted;
    }

    /* ---------- 照合ロジック ---------- */

    /** name 一致（前後空白無視）または brand 一致（bt→URL由来）で campaign がヒットするか */
    private function campaign_matches($campaign, $name, $brand, $bt_map) {
        if ($name !== '' && isset($campaign['name']) && $this->norm_name($campaign['name']) === $this->norm_name($name)) {
            return true;
        }
        if ($brand !== '') {
            $want = strtolower($this->norm_name($brand));
            foreach ($this->brands_for_bts($this->extract_bts($campaign), $bt_map) as $b) {
                if ($b === $want) return true;
            }
        }
        return false;
    }

    /* ---------- ブランド導出（本体の group_name_from_url 相当） ---------- */

    /** bt => URL 対応表をキー小文字化して返す */
    private function bt_map_lower() {
        $m = get_option(self::BT_MAP_OPTION, []);
        if (!is_array($m)) return [];
        $out = [];
        foreach ($m as $bt => $url) {
            $out[strtolower((string) $bt)] = (string) $url;
        }
        return $out;
    }

    /** URLの末尾セグメント（拡張子除去・小文字）をブランド名として返す */
    private function brand_from_url($url) {
        $parts = wp_parse_url($url);
        if (empty($parts['path'])) return '';
        $segments = array_values(array_filter(explode('/', $parts['path']), 'strlen'));
        if (empty($segments)) return '';
        $last = end($segments);
        $name = preg_replace('/\.(html?|php|aspx?|jsp)$/i', '', $last);
        $name = trim($name);
        return $name === '' ? '' : strtolower($name);
    }

    /** btの配列 → そのリンク先URLから導いたブランド名（重複排除） */
    private function brands_for_bts($bts, $bt_map) {
        $brands = [];
        foreach ($bts as $bt) {
            $key = strtolower($this->norm_bt($bt));
            if (isset($bt_map[$key])) {
                $b = $this->brand_from_url($bt_map[$key]);
                if ($b !== '') $brands[$b] = true;
            }
        }
        return array_keys($brands);
    }

    /* ---------- データ入出力（本体と同じメタを共有） ---------- */

    private function read_campaigns($post_id) {
        $data = get_post_meta($post_id, self::META_KEY, true);
        return is_array($data) ? $data : [];
    }

    private function write_campaigns($post_id, array $campaigns) {
        if (empty($campaigns)) {
            delete_post_meta($post_id, self::META_KEY);
        } else {
            update_post_meta($post_id, self::META_KEY, $campaigns);
        }
        $this->purge_post_cache($post_id);
    }

    private function extract_bts($campaign) {
        $bts = [];
        if (!empty($campaign['values']) && is_array($campaign['values'])) {
            foreach ($campaign['values'] as $v) {
                if (is_array($v) && isset($v['bt']) && $v['bt'] !== '') $bts[] = $v['bt'];
                elseif (is_string($v) && $v !== '')                     $bts[] = $v;
            }
        }
        return $bts;
    }

    private function norm_name($s) {
        return trim((string) $s);
    }

    /** 本体 normalize_bt 相当（記号除去・bt接頭辞付与） */
    private function norm_bt($raw) {
        $s = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $raw);
        if ($s === '') return '';
        if (stripos($s, 'bt') !== 0) $s = 'bt' . $s;
        return $s;
    }

    /** 本体 purge_post_cache と同等のキャッシュ破棄（非表示CSSはページHTMLに埋まるため必須） */
    private function purge_post_cache($post_id) {
        $post_id = (int) $post_id;
        if (!$post_id) return;

        clean_post_cache($post_id);

        if (function_exists('rocket_clean_post'))           rocket_clean_post($post_id);
        if (function_exists('w3tc_flush_post'))             w3tc_flush_post($post_id);
        if (function_exists('wp_cache_post_change'))        wp_cache_post_change($post_id);
        if (function_exists('wpfc_clear_post_cache_by_id')) wpfc_clear_post_cache_by_id($post_id);
        if (function_exists('sg_cachepress_purge_cache'))   sg_cachepress_purge_cache(get_permalink($post_id));

        do_action('litespeed_purge_post', $post_id);
        do_action('cache_enabler_clear_page_cache_by_post', $post_id);
    }
}

new ALM_Connector();

/* ---------- GitHub リリースからの自動更新 ----------
 * WP標準の Update URI 機構（WP5.8+）。更新情報はリポジトリ内の静的マニフェスト
 * updates.json（raw.githubusercontent.com）から取得する。
 * GitHub API と違いレート制限が実質無く、共用サーバーでも安定して確認できる。
 * 「更新 → もう一度確認する」(force-check) 時はキャッシュを無視して即時確認。
 * 取得失敗は10分だけキャッシュ（成功は6時間）。
 */
add_filter('update_plugins_github.com', function ($update, $plugin_data, $plugin_file) {
    if ($plugin_file !== plugin_basename(__FILE__)) return $update;

    $cache_key = 'alm_connector_gh_update';
    $force = is_admin() && !empty($_GET['force-check']);
    $info = $force ? false : get_transient($cache_key);
    if ($info === false || !is_array($info)) {
        $info = ['version' => '', 'package' => ''];
        $ttl  = 10 * MINUTE_IN_SECONDS; // 失敗時は短めに再試行
        $res = wp_remote_get(
            'https://raw.githubusercontent.com/terasakisd/alm-plugins/main/updates.json',
            ['timeout' => 10, 'headers' => ['Accept' => 'application/json', 'User-Agent' => 'WordPress; ' . home_url('/')]]
        );
        if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
            $all = json_decode(wp_remote_retrieve_body($res), true);
            $me  = (is_array($all) && isset($all['alm-connector']) && is_array($all['alm-connector'])) ? $all['alm-connector'] : null;
            if ($me && !empty($me['version']) && !empty($me['package'])) {
                $info = ['version' => (string) $me['version'], 'package' => (string) $me['package']];
                $ttl  = 6 * HOUR_IN_SECONDS;
            }
        }
        set_transient($cache_key, $info, $ttl);
    }

    if (!empty($info['version']) && !empty($info['package'])
        && version_compare($info['version'], $plugin_data['Version'], '>')) {
        return [
            'slug'    => dirname($plugin_file),
            'version' => $info['version'],
            'url'     => 'https://github.com/terasakisd/alm-plugins',
            'package' => $info['package'],
        ];
    }
    return $update;
}, 10, 3);
