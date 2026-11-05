<?php
/**
 * Plugin Name: 広告リンク管理 (Ad Link Manager)
 * Description: 投稿・固定ページごとに、案件単位で複数のbt ID / キーワードを登録し、案件まるごとワンクリックで表示/非表示切替できるメタボックス。判定方法はサイト単位で設定。
 * Version: 6.9.0
 * Update URI: https://github.com/terasakisd/alm-plugins
 * Author: Mikuru
 */

if (!defined('ABSPATH')) exit;

class Ad_Link_Manager {

    const META_KEY      = '_alm_ad_campaigns';
    const SITE_OPTION   = 'alm_match_type';  // 'bt' | 'keyword'
    const JS_URL_OPTION = 'alm_js_url';      // 解析対象JS（またはインライン埋込ページ）のURL
    const BT_MAP_OPTION = 'alm_bt_map';      // [bt001 => 'https://...']
    const ENQUEUE_JS_OPTION = 'alm_enqueue_js'; // '1'ならフロントでaccess.jsを読み込む
    const AUTO_REFRESH_OPTION = 'alm_auto_refresh'; // '' | 'hourly' | 'twicedaily' | 'daily'
    const LAST_REFRESH_OPTION = 'alm_last_refresh'; // 最終自動更新の結果 ['time','ok','count','msg']
    const CRON_HOOK     = 'alm_cron_refresh_map';   // WP-Cronイベント名
    const NONCE_KEY     = 'alm_nonce';
    const POST_TYPES    = ['post', 'page'];

    public function __construct() {
        add_action('add_meta_boxes',        [$this, 'add_meta_box']);
        // 通常保存（親投稿）
        add_action('save_post',             [$this, 'save_meta'], 10, 2);
        // リビジョン/autosave保存時（プレビュー用）
        add_action('save_post',             [$this, 'save_revision_meta_via_post'], 10, 3);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Ajax保存（メタボックス内の保存ボタン用）
        add_action('wp_ajax_alm_quick_save', [$this, 'ajax_quick_save']);
        // Ajax: JSファイル解析
        add_action('wp_ajax_alm_parse_js',   [$this, 'ajax_parse_js']);
        // Ajax: access.js URL自動検出
        add_action('wp_ajax_alm_detect_js',  [$this, 'ajax_detect_js']);
        // Ajax: bt対応表のリセット
        add_action('wp_ajax_alm_reset_map',  [$this, 'ajax_reset_map']);
        // Ajax: ワンクリック自動セットアップ（検出→URL保存→解析を一括実行）
        add_action('wp_ajax_alm_auto_setup', [$this, 'ajax_auto_setup']);
        // Ajax: 記事内のaタグリンクを一括取得（編集画面用）
        add_action('wp_ajax_alm_scan_links', [$this, 'ajax_scan_links']);

        // 外部ツール連携（コネクタ）：プラグインの情報・処理を渡すためのフィルター
        add_filter('alm_connector_get_campaigns',   [$this, 'connector_get_campaigns'], 10, 2);
        add_filter('alm_connector_clean_campaigns', [$this, 'connector_clean_campaigns'], 10, 2);
        add_filter('alm_connector_scan_links',      [$this, 'connector_scan_links'], 10, 2);
        add_filter('alm_connector_bt_map',          [$this, 'connector_bt_map'], 10, 1);
        add_filter('alm_connector_match_type',      [$this, 'connector_match_type'], 10, 1);

        // 有効化時に自動セットアップを試みる（失敗しても有効化は継続）
        register_activation_hook(__FILE__, [$this, 'on_activate']);
        // 無効化時にスケジュール済みの自動更新を解除
        register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);

        // access.js自動更新（WP-Cron）: 設定と実際のスケジュールを毎回突き合わせて整合させる
        add_action('init',           [$this, 'maybe_schedule_cron']);
        add_action(self::CRON_HOOK,  [$this, 'cron_refresh_bt_map']);

        // サイト設定ページ
        add_action('admin_menu',            [$this, 'add_settings_page']);
        add_action('admin_init',            [$this, 'register_settings']);

        // フロント出力
        add_action('wp_head',               [$this, 'output_frontend_hide']);

        // 設置済(未読込)サイト対応: 設定されたaccess.jsをフロントで読み込む（任意）
        add_action('wp_enqueue_scripts',    [$this, 'maybe_enqueue_access_js']);

        // Autoptimize対応: access.jsを結合・最適化・遅延の対象から除外する
        // （結合されるとsrcが autoptimize_xxx.js になり検出できなくなる＋クリック計測が壊れる恐れ）
        add_filter('autoptimize_filter_js_exclude',  [$this, 'autoptimize_exclude_access_js']);
        add_filter('autoptimize_filter_js_dontmove', [$this, 'array_exclude_access_js']);
        // WP Rocket対応: 同様に結合・遅延読み込みから除外
        add_filter('rocket_exclude_js',              [$this, 'array_exclude_access_js']);
        add_filter('rocket_exclude_defer_js',        [$this, 'array_exclude_access_js']);
        add_filter('rocket_delay_js_exclusions',     [$this, 'array_exclude_access_js']);
    }

    /** Autoptimizeの除外リスト（カンマ区切り文字列）にaccess.jsを追加 */
    public function autoptimize_exclude_access_js($exclude) {
        $exclude = trim((string)$exclude);
        if (stripos($exclude, 'access.js') !== false) return $exclude;
        return $exclude === '' ? 'access.js' : $exclude . ', access.js';
    }

    /** 配列形式の除外リストにaccess.jsを追加（Autoptimize dontmove / WP Rocket用） */
    public function array_exclude_access_js($exclude) {
        if (!is_array($exclude)) $exclude = [];
        if (!in_array('access.js', $exclude, true)) $exclude[] = 'access.js';
        return $exclude;
    }

    /** 設置済(未読込)サイト対応: テーマが読み込んでいないaccess.jsをプラグイン側で読み込む */
    public function maybe_enqueue_access_js() {
        if (!get_option(self::ENQUEUE_JS_OPTION)) return;
        $url = get_option(self::JS_URL_OPTION, '');
        if ($url === '') return;
        // インライン埋込サイト（ページURLが設定されている）の場合は対象外
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!$path || !preg_match('/\.js$/i', $path)) return;
        wp_enqueue_script('alm-access-js', $url, [], null, true);
    }

    public function get_match_type() {
        $t = get_option(self::SITE_OPTION, 'bt');
        return $t === 'keyword' ? 'keyword' : 'bt';
    }

    /* ---------- サイト設定ページ ---------- */

    public function add_settings_page() {
        add_options_page(
            '広告リンク管理 設定',
            '広告リンク管理',
            'manage_options',
            'alm-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('alm_settings_group', self::SITE_OPTION, [
            'type' => 'string',
            'sanitize_callback' => function ($v) {
                return $v === 'keyword' ? 'keyword' : 'bt';
            },
            'default' => 'bt',
        ]);
        register_setting('alm_settings_group', self::JS_URL_OPTION, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ]);
        register_setting('alm_settings_group', self::AUTO_REFRESH_OPTION, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_auto_refresh'],
            'default' => '',
        ]);
        // 注: ENQUEUE_JS_OPTION は設定画面には出さない（自動セットアップが自動管理する）。
        // フォーム保存でリセットされないよう register_setting にも登録しない。
    }

    /**
     * 自動更新間隔の入力値を許可リストに丸めて返すだけ。
     * ここで update_option を呼ぶと sanitize が再帰して無限ループ→500になるので呼ばない。
     * スケジュールの整合は保存後の次回読み込み時に init → maybe_schedule_cron が行う。
     */
    public function sanitize_auto_refresh($v) {
        return in_array($v, ['hourly', 'twicedaily', 'daily'], true) ? $v : '';
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $current = $this->get_match_type();
        $js_url  = get_option(self::JS_URL_OPTION, '');
        $map     = get_option(self::BT_MAP_OPTION, []);
        if (!is_array($map)) $map = [];
        $map_count = count($map);
        $nonce = wp_create_nonce(self::NONCE_KEY);
        $bt_only_style = $current === 'bt' ? '' : 'display:none;';
        ?>
        <div class="wrap">
            <h1>広告リンク管理 設定</h1>

            <div class="alm-bt-only" style="<?php echo $bt_only_style; ?>background:#fff; border:1px solid #c3c4c7; border-left:4px solid #2271b1; padding:12px 16px; margin:16px 0; max-width:760px">
                <p style="margin:0">
                    <button type="button" class="button button-primary button-hero" id="alm-auto-setup-btn">自動セットアップを実行</button>
                    <span id="alm-auto-setup-status" style="margin-left:10px"></span>
                </p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('alm_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">判定方法</th>
                        <td>
                            <label>
                                <input type="radio" name="<?php echo self::SITE_OPTION; ?>" value="bt"
                                       <?php checked($current, 'bt'); ?>>
                                bt ID（<code>id="bt001"</code> 形式）
                            </label><br>
                            <label>
                                <input type="radio" name="<?php echo self::SITE_OPTION; ?>" value="keyword"
                                       <?php checked($current, 'keyword'); ?>>
                                キーワード（hrefに含まれる文字列。<code>?</code>以降のリファラまで含めると末尾一致になり、リンク1本ずつ個別に判定できます）
                            </label>
                        </td>
                    </tr>
                    <tr class="alm-bt-only" style="<?php echo $bt_only_style; ?>">
                        <th scope="row">JSファイルのURL</th>
                        <td>
                            <input type="url" id="alm-js-url-input" name="<?php echo self::JS_URL_OPTION; ?>"
                                   value="<?php echo esc_attr($js_url); ?>"
                                   class="regular-text" style="width:100%; max-width:600px"
                                   placeholder="https://example.com/wp-content/themes/your-theme/js/access.js">
                            <p style="margin:6px 0">
                                <input type="url" id="alm-detect-page-url" class="regular-text" style="max-width:400px"
                                       placeholder="（任意）検出対象ページURL。空欄ならトップページ">
                                <button type="button" class="button" id="alm-detect-js-btn">自動検出</button>
                                <span id="alm-detect-status" style="margin-left:8px"></span>
                            </p>
                        </td>
                    </tr>
                    <tr class="alm-bt-only" style="<?php echo $bt_only_style; ?>">
                        <th scope="row">access.jsの自動更新</th>
                        <td>
                            <?php $auto = get_option(self::AUTO_REFRESH_OPTION, ''); ?>
                            <select name="<?php echo self::AUTO_REFRESH_OPTION; ?>">
                                <option value=""           <?php selected($auto, '');           ?>>自動更新しない</option>
                                <option value="hourly"     <?php selected($auto, 'hourly');     ?>>1時間ごと</option>
                                <option value="twicedaily" <?php selected($auto, 'twicedaily'); ?>>12時間ごと（1日2回）</option>
                                <option value="daily"      <?php selected($auto, 'daily');      ?>>1日1回</option>
                            </select>
                            <?php
                            $last = get_option(self::LAST_REFRESH_OPTION, []);
                            if (is_array($last) && !empty($last['time'])):
                                $when = wp_date('Y-m-d H:i', (int) $last['time']);
                            ?>
                            <p class="description" style="margin-top:6px">
                                最終自動更新：<strong><?php echo esc_html($when); ?></strong> —
                                <?php if (!empty($last['ok'])): ?>
                                    <span style="color:#2271b1">成功（<?php echo (int) $last['count']; ?>件）</span>
                                <?php else: ?>
                                    <span style="color:#d63638">失敗：<?php echo esc_html($last['msg']); ?></span>
                                <?php endif; ?>
                            </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <div class="alm-bt-only" style="<?php echo $bt_only_style; ?>">
            <hr>
            <h2>bt対応表</h2>
            <p>
                <button type="button" class="button button-primary" id="alm-parse-js-btn">
                    対応表を作成／更新
                </button>
                <button type="button" class="button" id="alm-reset-map-btn" style="color:#d63638; border-color:#d63638">
                    対応表をリセット
                </button>
                <span class="alm-parse-status" id="alm-parse-status" style="margin-left:12px"></span>
            </p>
            <p>現在の登録数：<strong id="alm-map-count"><?php echo $map_count; ?></strong> 件</p>

            <?php if (!empty($map)): ?>
            <details style="margin-top:12px" open>
                <summary style="cursor:pointer; font-weight:bold; font-size:14px">登録済みbt対応表（グループ別）</summary>
                <div style="margin-top:8px">
                <?php
                $groups = $this->group_bt_map($map);
                foreach ($groups as $gname => $items):
                ?>
                    <h3 style="margin:16px 0 4px; font-size:14px">
                        <?php echo esc_html($gname); ?>
                        <span style="font-weight:normal; color:#666">（<?php echo count($items); ?>件）</span>
                    </h3>
                    <table class="widefat striped" style="max-width:900px">
                        <thead><tr><th style="width:120px">bt ID</th><th>リンク先URL</th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $bt => $url): ?>
                            <tr>
                                <td><code><?php echo esc_html($bt); ?></code></td>
                                <td style="word-break:break-all"><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html($url); ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
            </div><!-- /.alm-bt-only -->

            <script>
            (function(){
                // 判定方法の切替に連動して、bt関連の項目を表示/非表示
                var radios = document.querySelectorAll('input[name="<?php echo self::SITE_OPTION; ?>"]');
                function toggleBtOnly() {
                    var isBt = true;
                    radios.forEach(function(r){ if (r.checked) isBt = (r.value === 'bt'); });
                    document.querySelectorAll('.alm-bt-only').forEach(function(el){
                        el.style.display = isBt ? '' : 'none';
                    });
                }
                radios.forEach(function(r){ r.addEventListener('change', toggleBtOnly); });

                var btn = document.getElementById('alm-parse-js-btn');
                var status = document.getElementById('alm-parse-status');
                if (btn) {
                    btn.addEventListener('click', function(){
                        btn.disabled = true;
                        status.textContent = '解析中...';
                        status.style.color = '';
                        var fd = new FormData();
                        fd.append('action', 'alm_parse_js');
                        fd.append('nonce', '<?php echo $nonce; ?>');
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method:'POST', body: fd, credentials: 'same-origin' })
                            .then(function(r){ return r.json(); })
                            .then(function(res){
                                if (res && res.success) {
                                    status.textContent = '完了：' + res.data.count + '件のbt対応を取得しました。ページを更新すると一覧が反映されます。';
                                    status.style.color = '#2271b1';
                                    var mc = document.getElementById('alm-map-count');
                                    if (mc) mc.textContent = res.data.count;
                                } else {
                                    status.textContent = '失敗：' + ((res && res.data && res.data.msg) || '不明なエラー');
                                    status.style.color = '#d63638';
                                }
                            })
                            .catch(function(e){
                                status.textContent = '通信エラー: ' + e.message;
                                status.style.color = '#d63638';
                            })
                            .finally(function(){ btn.disabled = false; });
                    });
                }

                // リセットボタン
                var rbtn = document.getElementById('alm-reset-map-btn');
                if (rbtn) {
                    rbtn.addEventListener('click', function(){
                        if (!confirm('bt対応表をすべて削除します。よろしいですか？\n\n（投稿の案件設定は削除されません。対応表のみリセットされます。）')) return;
                        rbtn.disabled = true;
                        status.textContent = 'リセット中...';
                        status.style.color = '';
                        var fd = new FormData();
                        fd.append('action', 'alm_reset_map');
                        fd.append('nonce', '<?php echo $nonce; ?>');
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method:'POST', body: fd, credentials: 'same-origin' })
                            .then(function(r){ return r.json(); })
                            .then(function(res){
                                if (res && res.success) {
                                    status.textContent = '対応表をリセットしました。ページを再読み込みします。';
                                    status.style.color = '#2271b1';
                                    setTimeout(function(){ location.reload(); }, 800);
                                } else {
                                    status.textContent = '失敗：' + ((res && res.data && res.data.msg) || '不明なエラー');
                                    status.style.color = '#d63638';
                                    rbtn.disabled = false;
                                }
                            })
                            .catch(function(e){
                                status.textContent = '通信エラー: ' + e.message;
                                status.style.color = '#d63638';
                                rbtn.disabled = false;
                            });
                    });
                }

                // 自動検出ボタン
                var dbtn = document.getElementById('alm-detect-js-btn');
                var dstatus = document.getElementById('alm-detect-status');
                var input = document.getElementById('alm-js-url-input');
                var pageInput = document.getElementById('alm-detect-page-url');
                var TYPE_LABELS = {
                    direct:      '直接読込',
                    autoptimize: 'Autoptimize等の結合JS',
                    inline:      'インライン埋込（ページURL）',
                    theme:       'テーマ内に設置済み（ページ未読込）'
                };
                if (dbtn && input) {
                    dbtn.addEventListener('click', function(){
                        dbtn.disabled = true;
                        dstatus.textContent = '検出中...';
                        dstatus.style.color = '';
                        var fd = new FormData();
                        fd.append('action', 'alm_detect_js');
                        fd.append('nonce', '<?php echo $nonce; ?>');
                        fd.append('page_url', pageInput ? pageInput.value : '');
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method:'POST', body: fd, credentials: 'same-origin' })
                            .then(function(r){ return r.json(); })
                            .then(function(res){
                                if (res && res.success) {
                                    var cands = res.data.candidates || [];
                                    function adopt(c){
                                        input.value = c.url;
                                        var label = TYPE_LABELS[c.type] || c.type;
                                        dstatus.textContent = '見つかりました［' + label + '］：' + c.url + '（「変更を保存」を押してください）';
                                        dstatus.style.color = '#2271b1';
                                    }
                                    if (cands.length === 1) {
                                        adopt(cands[0]);
                                    } else if (cands.length > 1) {
                                        var msg = '候補が ' + cands.length + ' 個見つかりました。\n採用したいURLを選んでください：\n\n';
                                        cands.forEach(function(c, i){ msg += (i+1) + '. [' + (TYPE_LABELS[c.type] || c.type) + '] ' + c.url + '\n'; });
                                        msg += '\n採用する番号を入力してください（キャンセルで何もしない）：';
                                        var pick = prompt(msg, '1');
                                        var idx = parseInt(pick, 10) - 1;
                                        if (!isNaN(idx) && cands[idx]) {
                                            adopt(cands[idx]);
                                        } else {
                                            dstatus.textContent = 'キャンセルしました';
                                            dstatus.style.color = '';
                                        }
                                    }
                                } else {
                                    dstatus.textContent = '失敗：' + ((res && res.data && res.data.msg) || '不明なエラー');
                                    dstatus.style.color = '#d63638';
                                }
                            })
                            .catch(function(e){
                                dstatus.textContent = '通信エラー: ' + e.message;
                                dstatus.style.color = '#d63638';
                            })
                            .finally(function(){ dbtn.disabled = false; });
                    });
                }

                // ワンクリック自動セットアップボタン
                var abtn = document.getElementById('alm-auto-setup-btn');
                var astatus = document.getElementById('alm-auto-setup-status');
                if (abtn) {
                    abtn.addEventListener('click', function(){
                        abtn.disabled = true;
                        astatus.textContent = '実行中...（検出→保存→解析）';
                        astatus.style.color = '';
                        var fd = new FormData();
                        fd.append('action', 'alm_auto_setup');
                        fd.append('nonce', '<?php echo $nonce; ?>');
                        fd.append('page_url', pageInput ? pageInput.value : '');
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method:'POST', body: fd, credentials: 'same-origin' })
                            .then(function(r){ return r.json(); })
                            .then(function(res){
                                if (res && res.success) {
                                    var d = res.data;
                                    var label = TYPE_LABELS[d.type] || d.type;
                                    var note = d.enqueued ? ' / 自動読み込みをONにしました' : '';
                                    astatus.textContent = '完了：［' + label + '］' + d.count + '件のbt対応を取得' + note + '。ページを再読み込みします...';
                                    astatus.style.color = '#2271b1';
                                    setTimeout(function(){ location.reload(); }, 1500);
                                } else {
                                    astatus.textContent = '失敗：' + ((res && res.data && res.data.msg) || '不明なエラー');
                                    astatus.style.color = '#d63638';
                                    abtn.disabled = false;
                                }
                            })
                            .catch(function(e){
                                astatus.textContent = '通信エラー: ' + e.message;
                                astatus.style.color = '#d63638';
                                abtn.disabled = false;
                            });
                    });
                }
            })();
            </script>
        </div>
        <?php
    }

    /** Ajax: bt対応表をリセット（option削除） */
    public function ajax_reset_map() {
        check_ajax_referer(self::NONCE_KEY, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['msg' => '権限がありません'], 403);
        }
        delete_option(self::BT_MAP_OPTION);
        wp_send_json_success();
    }

    /** Ajax: ページのHTMLからaccess.js（直接読込・Autoptimize結合・インライン埋込・テーマ内設置）を検出 */
    public function ajax_detect_js() {
        check_ajax_referer(self::NONCE_KEY, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['msg' => '権限がありません'], 403);
        }

        $page_url = isset($_POST['page_url']) ? esc_url_raw(wp_unslash($_POST['page_url'])) : '';
        if (empty($page_url)) $page_url = home_url('/');

        $found = $this->detect_candidates($page_url);
        if ($found === null) {
            wp_send_json_error(['msg' => 'ページ取得に失敗し、テーマフォルダ内にもbtコードを含むJSが見つかりませんでした: ' . $page_url]);
        }
        if (empty($found)) {
            wp_send_json_error(['msg' => 'access.js が見つかりませんでした。インライン埋込サイトの場合は、埋め込まれている記事ページのURLを「検出対象ページURL」に入力して再検出してください。']);
        }

        wp_send_json_success([
            'candidates' => $found,             // 見つかった全候補（typeつき）
            'primary'    => $found[0]['url'],   // 採用するURL（最初の候補）
        ]);
    }

    /**
     * 検出本体: 候補リストを返す（ページ取得失敗かつローカルにも候補なしの場合はnull）
     * 高速化のため、HTTPリクエストは原則ページHTMLの1回だけ。
     * JSファイルの中身確認はローカルファイル読み込みを優先し、HTTPは最終手段。
     */
    private function detect_candidates($page_url) {
        // ローカルのテーマ走査はHTTP不要で一瞬なので先に済ませておく
        $theme_files = $this->scan_theme_dirs_for_bt_js();

        $html = $this->fetch_body($page_url, 10);
        if ($html === null) {
            // ループバック接続が遅い/塞がれているサーバーでも、ローカル走査の結果だけは返す
            if (empty($theme_files)) return null;
            $found = [];
            foreach ($theme_files as $u) $found[] = ['url' => $u, 'type' => 'theme'];
            return $found;
        }

        $found = []; // [['url' => ..., 'type' => 'direct'|'autoptimize'|'inline'|'theme'], ...]
        $seen  = [];

        // 1) <script src="...access.js..."> を探す（src / data-src、クエリパラメータ付きにも対応）
        if (preg_match_all('/<script[^>]+(?:src|data-src)=["\']([^"\']*access\.js(?:\?[^"\']*)?)["\']/i', $html, $m)) {
            foreach ($m[1] as $src) {
                $abs = $this->absolutize_url($src, $page_url);
                // クエリ削除版でユニーク化
                $clean = preg_replace('/\?.*$/', '', $abs);
                if (isset($seen[$clean])) continue;
                $seen[$clean] = true;
                $found[] = ['url' => $clean, 'type' => 'direct'];
            }
            if (!empty($found)) return $found;
        }

        // 2) インライン<script>内にbtコードが埋め込まれていないか確認（追加リクエスト不要）
        if (preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#is', $html, $m)) {
            foreach ($m[1] as $code) {
                if ($this->looks_like_bt_js($code)) {
                    // インライン埋込：このページのURL自体を解析対象として使う
                    return [['url' => $page_url, 'type' => 'inline']];
                }
            }
        }

        // 3) Autoptimize等の結合JSの中にbtコードが入っていないか確認
        //    キャッシュファイルはローカルで直接読む（HTTPは読めなかった場合のみ・短いタイムアウトで）
        if (preg_match_all('/<script[^>]+(?:src|data-src)=["\']([^"\']+\.js(?:\?[^"\']*)?)["\']/i', $html, $m)) {
            $checked = 0;
            foreach ($m[1] as $src) {
                // 結合・キャッシュ系のJSだけ中身を確認する（全JSを確認すると重いため）
                if (!preg_match('#(autoptimize|/wp-content/cache/|/min/|w3tc|wp-rocket)#i', $src)) continue;
                if ($checked >= 5) break;
                $checked++;
                $abs  = $this->absolutize_url($src, $page_url);
                $body = $this->local_body_for_url($abs);
                if ($body === null) $body = $this->fetch_body($abs, 6);
                if ($body !== null && $this->looks_like_bt_js($body)) {
                    // 解析対象には、キャッシュURL（内容が変わると無効になる）より
                    // テーマ内の元ファイルがあればそちらを優先する
                    $url = !empty($theme_files) ? $theme_files[0] : $abs;
                    return [['url' => $url, 'type' => 'autoptimize']];
                }
            }
        }

        // 4) テーマ内に設置されているがページから読み込まれていないファイル
        foreach ($theme_files as $candidate) {
            if (isset($seen[$candidate])) continue;
            $seen[$candidate] = true;
            $found[] = ['url' => $candidate, 'type' => 'theme'];
        }

        return $found;
    }

    /**
     * 同一サイト内のwp-content配下URLならローカルファイルとして直接読む
     * （サイト自身へのHTTPリクエストを避けるための高速化。読めなければnull）
     */
    private function local_body_for_url($url) {
        $u = wp_parse_url(preg_replace('/[?#].*$/', '', $url));
        if (empty($u['path'])) return null;

        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!empty($u['host']) && !empty($site_host) && strcasecmp($u['host'], $site_host) !== 0) return null;

        $content_path = wp_parse_url(content_url(), PHP_URL_PATH);
        if (!$content_path || strpos($u['path'], $content_path . '/') !== 0) return null;

        $path = WP_CONTENT_DIR . substr($u['path'], strlen($content_path));
        // パストラバーサル防止
        $real = realpath($path);
        if ($real === false || strpos($real, realpath(WP_CONTENT_DIR)) !== 0) return null;
        if (!is_file($real) || !is_readable($real)) return null;

        $body = @file_get_contents($real);
        return ($body === false || $body === '') ? null : $body;
    }

    /**
     * ワンクリック自動セットアップ: 検出 → URL保存 → bt対応表の作成 まで一括実行
     * 「設置済(未読込)」の場合は自動読み込みもONにする（ページが読み込んでいないので二重読み込みの心配なし）
     */
    public function auto_setup($page_url = '') {
        if (empty($page_url)) $page_url = home_url('/');

        $candidates = $this->detect_candidates($page_url);
        if ($candidates === null) {
            return ['ok' => false, 'msg' => 'ページ取得に失敗し、テーマフォルダ内にもbtコードを含むJSが見つかりませんでした: ' . $page_url];
        }
        if (empty($candidates)) {
            return ['ok' => false, 'msg' => 'access.js（btコード）が見つかりませんでした。インライン埋込サイトの場合は、埋め込まれている記事ページのURLを指定して再実行してください。'];
        }

        $best = $candidates[0];
        update_option(self::JS_URL_OPTION, esc_url_raw($best['url']));

        $enqueued = false;
        if ($best['type'] === 'theme') {
            update_option(self::ENQUEUE_JS_OPTION, '1');
            $enqueued = true;
        }

        $body = $this->fetch_body($best['url']);
        if ($body === null) {
            return ['ok' => false, 'msg' => 'URLは保存しましたが、ファイル取得に失敗しました: ' . $best['url']];
        }
        $map = $this->parse_bt_map_from_js($body);
        if (empty($map)) {
            return ['ok' => false, 'msg' => 'URLは保存しましたが、bt対応が1件も見つかりませんでした: ' . $best['url']];
        }
        update_option(self::BT_MAP_OPTION, $map, false);

        return [
            'ok'       => true,
            'url'      => $best['url'],
            'type'     => $best['type'],
            'count'    => count($map),
            'enqueued' => $enqueued,
        ];
    }

    /** Ajax: ワンクリック自動セットアップ */
    public function ajax_auto_setup() {
        check_ajax_referer(self::NONCE_KEY, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['msg' => '権限がありません'], 403);
        }
        $page_url = isset($_POST['page_url']) ? esc_url_raw(wp_unslash($_POST['page_url'])) : '';
        $res = $this->auto_setup($page_url);
        if (!empty($res['ok'])) {
            wp_send_json_success($res);
        }
        wp_send_json_error(['msg' => $res['msg']]);
    }

    /** プラグイン有効化時: 自動セットアップを試みる（失敗しても有効化は止めない） */
    public function on_activate() {
        try {
            $this->auto_setup();
        } catch (\Throwable $e) {
            // 設定画面のボタンから手動で再実行できるので握りつぶす
        }
    }

    /**
     * テーマフォルダ（子・親）をローカル走査し、btコードを含むJSファイルのURLを返す
     * ファイル名がaccess.jsでなくても中身で判定する
     */
    private function scan_theme_dirs_for_bt_js() {
        $found = [];
        // 子テーマと親テーマ。同一なら配列キーで自然に重複排除される
        $pairs = [
            get_stylesheet_directory() => get_stylesheet_directory_uri(),
            get_template_directory()   => get_template_directory_uri(),
        ];

        foreach ($pairs as $dir => $uri) {
            if (!is_dir($dir)) continue;
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
            } catch (Exception $e) {
                continue;
            }
            $checked = 0;
            foreach ($it as $file) {
                if (strtolower($file->getExtension()) !== 'js') continue;
                $path = $file->getPathname();
                if (strpos($path, 'node_modules') !== false) continue;
                if ($file->getSize() > 2 * 1024 * 1024) continue; // 巨大ファイルはスキップ
                if (++$checked > 500) break; // 走査上限（安全弁）
                $code = @file_get_contents($path);
                if ($code === false || !$this->looks_like_bt_js($code)) continue;
                $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($dir)));
                $found[] = $uri . $rel;
            }
        }
        return array_values(array_unique($found));
    }

    /** URLを取得して本文を返す（失敗時はnull）。同一サイト内のファイルはローカル読みを優先 */
    private function fetch_body($url, $timeout = 15) {
        $local = $this->local_body_for_url($url);
        if ($local !== null) return $local;

        $resp = wp_remote_get($url, ['timeout' => $timeout, 'sslverify' => true, 'redirection' => 5]);
        if (is_wp_error($resp)) return null;
        if (wp_remote_retrieve_response_code($resp) !== 200) return null;
        $body = wp_remote_retrieve_body($resp);
        return $body === '' ? null : $body;
    }

    /** コードがaccess.js風（bt番号→URLの対応を含む）かどうか判定 */
    private function looks_like_bt_js($code) {
        if (preg_match('/\bbtn?\d+\s*=\s*[\'"]https?:/i', $code)) return true;
        if (preg_match('/getElementById\(\s*[\'"]bt\d+/i', $code)) return true;
        if (preg_match('/[\'"]bt\d+[\'"]\s*:\s*[\'"]https?:/i', $code)) return true;
        return false;
    }

    /** 相対URLを絶対URLに変換 */
    private function absolutize_url($src, $base) {
        if (preg_match('#^https?://#i', $src)) return $src;
        if (strpos($src, '//') === 0) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $src;
        }
        $base_parts = wp_parse_url($base);
        $scheme = $base_parts['scheme'] ?? 'https';
        $host   = $base_parts['host']   ?? '';
        if (strpos($src, '/') === 0) {
            return $scheme . '://' . $host . $src;
        }
        // 相対パス
        $path = $base_parts['path'] ?? '/';
        $dir  = rtrim(dirname($path), '/');
        return $scheme . '://' . $host . $dir . '/' . $src;
    }

    /** Ajax: JSファイルを取得してパースし、bt→URL対応表を保存 */
    public function ajax_parse_js() {
        check_ajax_referer(self::NONCE_KEY, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['msg' => '権限がありません'], 403);
        }

        $res = $this->refresh_bt_map();
        if (!empty($res['ok'])) {
            wp_send_json_success(['count' => $res['count']]);
        }
        wp_send_json_error(['msg' => $res['msg']]);
    }

    /**
     * 保存済みURLからaccess.jsを取得し、bt→URL対応表を再生成して保存する。
     * 手動の「対応表を作成／更新」とWP-Cronの自動更新で共通利用する。
     * @return array ['ok'=>bool, 'count'=>int, 'msg'=>string]
     */
    public function refresh_bt_map() {
        $url = get_option(self::JS_URL_OPTION, '');
        if (empty($url)) {
            return ['ok' => false, 'count' => 0, 'msg' => 'JSファイルのURLが設定されていません'];
        }

        // 同一サイト内のファイルはローカルで直接読む（高速）。読めない場合のみHTTPで取得
        $body = $this->local_body_for_url($url);
        if ($body === null) {
            $resp = wp_remote_get($url, ['timeout' => 15, 'sslverify' => true]);
            if (is_wp_error($resp)) {
                return ['ok' => false, 'count' => 0, 'msg' => 'ファイル取得に失敗: ' . $resp->get_error_message()];
            }
            $code = wp_remote_retrieve_response_code($resp);
            if ($code !== 200) {
                return ['ok' => false, 'count' => 0, 'msg' => 'ファイル取得失敗 (HTTP ' . $code . ')'];
            }
            $body = wp_remote_retrieve_body($resp);
            if (empty($body)) {
                return ['ok' => false, 'count' => 0, 'msg' => 'ファイルの中身が空です'];
            }
        }

        // インライン埋込対応: URLがHTMLページの場合もbtパターンの正規表現がそのまま機能する
        // （<script>内のコードもHTML全体から直接抽出できる）
        $map = $this->parse_bt_map_from_js($body);

        if (empty($map)) {
            return ['ok' => false, 'count' => 0, 'msg' => 'bt対応が1件も見つかりませんでした。URLが正しいか（インライン埋込サイトの場合は埋込ページのURLか）確認してください。'];
        }

        update_option(self::BT_MAP_OPTION, $map, false);

        return ['ok' => true, 'count' => count($map), 'msg' => ''];
    }

    /** WP-Cron: access.jsを再取得して対応表を更新し、結果を記録する */
    public function cron_refresh_bt_map() {
        $res = $this->refresh_bt_map();
        update_option(self::LAST_REFRESH_OPTION, [
            'time'  => time(),
            'ok'    => !empty($res['ok']),
            'count' => (int) $res['count'],
            'msg'   => (string) $res['msg'],
        ], false);
    }

    /** 設定（更新間隔）と実際のWP-Cronスケジュールを突き合わせて整合させる */
    public function maybe_schedule_cron() {
        $desired = get_option(self::AUTO_REFRESH_OPTION, '');
        if (!in_array($desired, ['hourly', 'twicedaily', 'daily'], true)) $desired = '';

        $current = wp_get_schedule(self::CRON_HOOK); // 未予約なら false

        if ($desired === '') {
            if ($current !== false) wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }

        // 間隔が変わった/未予約なら一旦解除して予約し直す
        if ($current !== $desired) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            wp_schedule_event(time() + 60, $desired, self::CRON_HOOK);
        }
    }

    /** 無効化時: スケジュール済みの自動更新イベントを解除 */
    public function on_deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * access.js 風のコードから bt番号 → URL の対応表を作る
     * 想定パターン:
     *   var bt535 = document.getElementById('bt535');
     *   if (bt535 != null){ bt535.addEventListener('click', function() {
     *       var btn535 = 'https://example.com/xxx';
     *       window_obj = window.open( btn535,"_blank");
     *   ...
     */
    public function parse_bt_map_from_js($js) {
        $map = [];

        // 最優先パターン0: getElementById('実id') の実idを、同じクリックブロック内のURLと対応付ける。
        // 非表示は #実id セレクタで行うため、要素の実idをキーにすれば確実に一致する。
        // （URL代入変数名 btnN と要素idが食い違うサイト ―例: id="bt001" だが var btn1=... ― でも崩れない）
        if (preg_match_all('/getElementById\(\s*[\'"]([^\'"]+)[\'"]\s*\)(.*?)(?=getElementById\(|$)/is', $js, $blocks, PREG_SET_ORDER)) {
            foreach ($blocks as $b) {
                $id = trim($b[1]);
                // bt系idのみ対象（wp-emoji-settings 等の無関係なidを除外）
                if ($id === '' || stripos($id, 'bt') !== 0) continue;
                if (preg_match('/[\'"](https?:\/\/[^\'"]+)[\'"]/i', $b[2], $um)) {
                    $map[$id] = $um[1];
                }
            }
        }
        // getElementById形式で取得できた場合は、それを正としてそのまま返す
        if (!empty($map)) {
            ksort($map);
            return $map;
        }

        // ↓フォールバック: getElementById を使わないサイト（連想配列形式・インライン埋込など）
        // パターン1: `var btn535 = 'https://...';` 形式（btn + 数字の変数にURL代入）
        if (preg_match_all('/\bbtn(\d+)\s*=\s*[\'"]([^\'"]+)[\'"]/i', $js, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $num = $match[1];
                $url = $match[2];
                if (preg_match('#^https?://#i', $url)) {
                    $map['bt' . $num] = $url;
                }
            }
        }
        // パターン2: `var bt535 = 'https://...';`（直接 bt + 数字 にURL代入）
        if (preg_match_all('/\bbt(\d+)\s*=\s*[\'"](https?:[^\'"]+)[\'"]/i', $js, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $key = 'bt' . $match[1];
                if (!isset($map[$key])) $map[$key] = $match[2];
            }
        }
        // パターン3: 連想配列っぽい `'bt535': 'https://...'` / `bt535: 'https://...'`
        if (preg_match_all('/[\'"]?(bt\d+)[\'"]?\s*:\s*[\'"](https?:[^\'"]+)[\'"]/i', $js, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $key = strtolower($match[1]);
                if (!isset($map[$key])) $map[$key] = $match[2];
            }
        }
        ksort($map);
        return $map;
    }

    /* ---------- メタボックス ---------- */

    public function add_meta_box() {
        foreach (self::POST_TYPES as $pt) {
            add_meta_box(
                'alm-meta-box',
                '広告リンク管理',
                [$this, 'render_meta_box'],
                $pt,
                'normal',
                'default'
            );
        }
    }

    public function enqueue_admin_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, self::POST_TYPES, true)) return;

        // バージョンはファイル更新時刻にして、JS/CSSを変更したら必ずブラウザに最新が読み込まれるようにする
        $css_path = plugin_dir_path(__FILE__) . 'admin.css';
        $js_path  = plugin_dir_path(__FILE__) . 'admin.js';
        $css_ver  = file_exists($css_path) ? filemtime($css_path) : null;
        $js_ver   = file_exists($js_path)  ? filemtime($js_path)  : null;

        wp_enqueue_style('alm-admin-css', plugins_url('admin.css', __FILE__), [], $css_ver);
        wp_enqueue_script('alm-admin-js', plugins_url('admin.js', __FILE__), ['jquery'], $js_ver, true);

        // 投稿IDを確実に取得
        global $post;
        $post_id = 0;
        if ($post && isset($post->ID)) $post_id = (int) $post->ID;
        if (!$post_id && isset($_GET['post'])) $post_id = (int) $_GET['post'];

        $bt_map_for_js = get_option(self::BT_MAP_OPTION, []);
        if (!is_array($bt_map_for_js)) $bt_map_for_js = [];

        wp_localize_script('alm-admin-js', 'ALM', [
            'matchType' => $this->get_match_type(),
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce(self::NONCE_KEY),
            'postId'    => $post_id,
            'btMap'     => $bt_map_for_js ?: new stdClass(),
            'btGroups'  => $this->group_bt_map($bt_map_for_js) ?: new stdClass(),
        ]);
    }

    public function render_meta_box($post) {
        wp_nonce_field(self::NONCE_KEY, self::NONCE_KEY);
        $campaigns = $this->get_campaigns($post->ID);
        $match     = $this->get_match_type();
        $label     = $match === 'bt' ? 'bt ID' : 'キーワード (href内)';
        $placeholder = $match === 'bt' ? 'bt001' : 'aiful';
        $settings_url = admin_url('options-general.php?page=alm-settings');
        $bt_map = get_option(self::BT_MAP_OPTION, []);
        if (!is_array($bt_map)) $bt_map = [];
        ?>
        <div class="alm-wrap" data-match="<?php echo esc_attr($match); ?>">
            <p class="description">
                判定方法：<strong><?php echo esc_html($label); ?></strong>
                （<a href="<?php echo esc_url($settings_url); ?>">設定</a>）
                <?php if ($match === 'bt' && !empty($bt_map)): ?>
                    / bt対応表：<strong><?php echo count($bt_map); ?></strong>件
                <?php endif; ?>
            </p>

            <?php if ($match === 'bt' && !empty($bt_map)): ?>
                <datalist id="alm-bt-datalist">
                    <?php foreach ($bt_map as $bt => $url): ?>
                        <option value="<?php echo esc_attr($bt); ?>" label="<?php echo esc_attr($url); ?>"><?php echo esc_html($url); ?></option>
                    <?php endforeach; ?>
                </datalist>
            <?php endif; ?>

            <div id="alm-campaigns" class="alm-campaigns">
                <?php if (empty($campaigns)): ?>
                    <div class="alm-empty">まだ案件が登録されていません。</div>
                <?php else: foreach ($campaigns as $ci => $camp): ?>
                    <?php $this->render_campaign($ci, $camp, $placeholder); ?>
                <?php endforeach; endif; ?>
            </div>

            <p style="margin-top:12px" class="alm-bottom-actions">
                <button type="button" class="button button-primary" id="alm-add-campaign">＋ 案件を追加</button>
                <button type="button" class="button button-secondary" id="alm-quick-save">この設定を保存</button>
                <button type="button" class="button" id="alm-scan-links">記事内リンクを一括取得</button>
                <?php if ($match === 'bt' && !empty($bt_map)): ?>
                    <button type="button" class="button" id="alm-import-from-js">JSから一括取り込み</button>
                <?php endif; ?>
                <span class="alm-status" id="alm-status" aria-live="polite"></span>
            </p>

            <!-- JS用のテンプレート -->
            <script type="text/template" id="alm-campaign-template">
                <?php $this->render_campaign('__INDEX__', ['name' => '', 'values' => [['bt' => '', 'replacement' => '', 'parent' => false, 'parent_selectors' => []]], 'disabled' => false], $placeholder); ?>
            </script>
            <script type="text/template" id="alm-value-template">
                <div class="alm-value-row alm-value-block">
                    <div class="alm-value-line">
                        <input type="text" name="alm_campaigns[__CI__][values][__VI__][bt]" value=""
                               class="regular-text alm-value" placeholder="<?php echo esc_attr($placeholder); ?>"
                               <?php if ($match === 'bt' && !empty($bt_map)): ?>list="alm-bt-datalist"<?php endif; ?>>
                        <button type="button" class="button alm-remove-value" title="削除">×</button>
                        <span class="alm-url-hint"></span>
                    </div>
                    <div class="alm-value-replacement">
                        <span class="alm-rep-label">↳ 代替テキスト:</span>
                        <input type="text" name="alm_campaigns[__CI__][values][__VI__][replacement]" value=""
                               class="regular-text alm-value-replacement-input"
                               placeholder="（任意）この案件を非表示にしたとき代わりに表示する文字">
                    </div>
                    <div class="alm-value-parent">
                        <label class="alm-parent-wrap-inline">
                            <input type="checkbox" class="alm-parent-toggle-bt"
                                   name="alm_campaigns[__CI__][values][__VI__][parent]" value="1">
                            <span>↳ 親要素ごと消す</span>
                        </label>
                        <div class="alm-parent-selectors-bt" style="display:none;">
                            <div class="alm-parent-row-bt">
                                <input type="text"
                                       name="alm_campaigns[__CI__][values][__VI__][parent_selectors][]"
                                       value=""
                                       class="regular-text alm-parent-selector-bt"
                                       placeholder=".btn-wrap">
                                <button type="button" class="button alm-remove-parent-bt" title="削除">×</button>
                            </div>
                            <p class="alm-value-actions">
                                <button type="button" class="button alm-add-parent-bt">＋ 親セレクタを追加</button>
                            </p>
                        </div>
                    </div>
                </div>
            </script>
        </div>
        <?php
    }

    private function render_campaign($ci, $camp, $placeholder) {
        $name     = isset($camp['name']) ? $camp['name'] : '';
        $values   = !empty($camp['values']) && is_array($camp['values']) ? $camp['values'] : [''];
        $disabled = !empty($camp['disabled']);
        $collapsed = !empty($camp['collapsed']);
        $val_count = 0;
        if (is_array($values)) {
            foreach ($values as $v) {
                $item = $this->normalize_value_item($v);
                if ($item['bt'] !== '') $val_count++;
            }
        }
        ?>
        <div class="alm-campaign <?php echo $collapsed ? 'is-collapsed' : ''; ?>" data-ci="<?php echo esc_attr($ci); ?>">
            <div class="alm-campaign-head">
                <button type="button" class="alm-toggle-collapse" aria-label="折りたたみ切替" title="折りたたみ／展開">
                    <span class="alm-caret"><?php echo $collapsed ? '▶' : '▼'; ?></span>
                </button>

                <input type="text" class="alm-campaign-name regular-text"
                       name="alm_campaigns[<?php echo esc_attr($ci); ?>][name]"
                       value="<?php echo esc_attr($name); ?>" placeholder="案件名（例：アイフル）">

                <span class="alm-campaign-summary"><?php echo $val_count; ?>件</span>

                <label class="alm-switch-wrap">
                    <span class="alm-switch">
                        <input type="checkbox" name="alm_campaigns[<?php echo esc_attr($ci); ?>][disabled]"
                               value="1" <?php checked($disabled); ?>>
                        <span class="alm-slider"></span>
                    </span>
                    <span class="alm-switch-label">コメントアウト</span>
                </label>

                <button type="button" class="button alm-delete-campaign">削除</button>

                <input type="hidden" class="alm-collapsed-state"
                       name="alm_campaigns[<?php echo esc_attr($ci); ?>][collapsed]"
                       value="<?php echo $collapsed ? '1' : '0'; ?>">
            </div>

            <div class="alm-campaign-body">
            <div class="alm-values">
                <?php
                $bt_map_local = get_option(self::BT_MAP_OPTION, []);
                if (!is_array($bt_map_local)) $bt_map_local = [];
                $has_datalist = ($placeholder === 'bt001' && !empty($bt_map_local));
                ?>
                <?php foreach ($values as $vi => $v):
                    $item = $this->normalize_value_item($v);
                    $bt_val      = $item['bt'];
                    $rep_val     = $item['replacement'];
                    $bt_parent   = !empty($item['parent']);
                    $bt_psels    = !empty($item['parent_selectors']) ? $item['parent_selectors'] : [''];
                    if (empty($bt_psels)) $bt_psels = [''];
                    $url_hint    = '';
                    if ($has_datalist && isset($bt_map_local[$bt_val])) $url_hint = $bt_map_local[$bt_val];
                ?>
                    <div class="alm-value-row alm-value-block" data-vi="<?php echo esc_attr($vi); ?>">
                        <div class="alm-value-line">
                            <input type="text" name="alm_campaigns[<?php echo esc_attr($ci); ?>][values][<?php echo esc_attr($vi); ?>][bt]"
                                   value="<?php echo esc_attr($bt_val); ?>"
                                   class="regular-text alm-value"
                                   placeholder="<?php echo esc_attr($placeholder); ?>"
                                   <?php if ($has_datalist): ?>list="alm-bt-datalist"<?php endif; ?>>
                            <button type="button" class="button alm-remove-value" title="削除">×</button>
                            <span class="alm-url-hint"><?php if ($url_hint): ?>→ <a href="<?php echo esc_url($url_hint); ?>" target="_blank" rel="noopener"><?php echo esc_html($this->short_url($url_hint)); ?></a><?php endif; ?></span>
                        </div>
                        <div class="alm-value-replacement">
                            <span class="alm-rep-label">↳ 代替テキスト:</span>
                            <input type="text" name="alm_campaigns[<?php echo esc_attr($ci); ?>][values][<?php echo esc_attr($vi); ?>][replacement]"
                                   value="<?php echo esc_attr($rep_val); ?>"
                                   class="regular-text alm-value-replacement-input"
                                   placeholder="（任意）この案件を非表示にしたとき代わりに表示する文字">
                        </div>
                        <div class="alm-value-parent">
                            <label class="alm-parent-wrap-inline">
                                <input type="checkbox" class="alm-parent-toggle-bt"
                                       name="alm_campaigns[<?php echo esc_attr($ci); ?>][values][<?php echo esc_attr($vi); ?>][parent]"
                                       value="1" <?php checked($bt_parent); ?>>
                                <span>↳ 親要素ごと消す</span>
                            </label>
                            <div class="alm-parent-selectors-bt" style="<?php echo $bt_parent ? '' : 'display:none;'; ?>">
                                <?php foreach ($bt_psels as $ps): ?>
                                    <div class="alm-parent-row-bt">
                                        <input type="text"
                                               name="alm_campaigns[<?php echo esc_attr($ci); ?>][values][<?php echo esc_attr($vi); ?>][parent_selectors][]"
                                               value="<?php echo esc_attr($ps); ?>"
                                               class="regular-text alm-parent-selector-bt"
                                               placeholder=".btn-wrap">
                                        <button type="button" class="button alm-remove-parent-bt" title="削除">×</button>
                                    </div>
                                <?php endforeach; ?>
                                <p class="alm-value-actions">
                                    <button type="button" class="button alm-add-parent-bt">＋ 親セレクタを追加</button>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="alm-value-actions">
                <button type="button" class="button alm-add-value">＋ <?php echo esc_html($placeholder === 'bt001' ? 'bt IDを追加' : 'キーワードを追加'); ?></button>
            </p>
            </div><!-- /.alm-campaign-body -->
        </div>
        <?php
    }

    /** Ajax: 記事本文からaタグリンクを抽出して返す（編集画面の一括取得用） */
    public function ajax_scan_links() {
        check_ajax_referer(self::NONCE_KEY, 'nonce');
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['msg' => '権限がありません'], 403);
        }
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['msg' => '記事が見つかりません']);
        }

        $base = get_permalink($post) ?: home_url('/');

        // まず公開ページの実際のHTMLから抽出する
        // （テーマ・ウィジェット・広告挿入機能などで表示時に差し込まれるリンクも拾えるように）
        $links = [];
        if ($post->post_status === 'publish') {
            $html = $this->fetch_body($base, 10);
            if ($html !== null) {
                $links = $this->extract_links_from_html($html, $base);
            }
        }

        // ページを取得できない場合（下書き等）は本文から抽出
        if (empty($links)) {
            $content = $post->post_content;
            try {
                if (function_exists('do_blocks')) $content = do_blocks($content);
                $content = do_shortcode($content);
            } catch (\Throwable $e) {
                $content = $post->post_content;
            }
            $links = $this->extract_links_from_html($content, $base);
        }

        if (empty($links)) {
            wp_send_json_error(['msg' => '記事内にリンクが見つかりませんでした']);
        }
        wp_send_json_success([
            'links'     => $links,
            'permalink' => $base,
            'slug'      => $post->post_name, // 広告リンク判定（.htmlリンクのURL内に記事スラッグを含む）に使用
        ]);
    }

    /** HTMLからaタグの href / id / テキスト を抽出する */
    public function extract_links_from_html($html, $base_url) {
        $links    = [];
        $seen     = [];
        $seen_ids = [];

        // (1) aタグ: href と id を拾う（従来どおり）
        if (preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $a) {
                $attrs = $a[1];
                $href = '';
                $id   = '';
                if (preg_match('/\bhref=["\']([^"\']*)["\']/i', $attrs, $hm)) $href = trim($hm[1]);
                if (preg_match('/\bid=["\']([^"\']*)["\']/i', $attrs, $im))   $id   = trim($im[1]);

                $is_real_href = ($href !== '' && $href[0] !== '#' && !preg_match('/^(javascript|mailto|tel):/i', $href));
                // bt ID付きでもなく、リンク先も無いものは広告リンクとして無意味なので除外
                if ($id === '' && !$is_real_href) continue;

                $abs = $is_real_href ? $this->absolutize_url($href, $base_url) : '';

                $key = $abs . '|' . $id;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                if ($id !== '') $seen_ids[strtolower($id)] = true;

                $text = trim(str_replace(['<!--', '-->'], '', wp_strip_all_tags($a[2])));
                if (mb_strlen($text) > 40) $text = mb_substr($text, 0, 40) . '…';

                $links[] = [
                    'href' => $abs,
                    'id'   => $id,
                    'text' => $text,
                    'host' => $abs ? (wp_parse_url($abs, PHP_URL_HOST) ?: '') : '',
                ];
            }
        }

        // (2) aタグ以外で id が bt で始まる要素（span / area / img / button 等）。
        //     リンクを張らず、JSのclickハンドラで遷移させる構成の記事に対応する。
        //     リンク先は持たないため href は空で返す（取り込み判定は呼び出し側の id 形式チェックに任せる）。
        if (preg_match_all('/<([a-z][a-z0-9]*)\b[^>]*\bid=["\'](bt[^"\']*)["\'][^>]*>/i', $html, $m2, PREG_SET_ORDER)) {
            $void_tags = ['area', 'img', 'input', 'br', 'hr', 'source', 'embed', 'track', 'wbr'];
            foreach ($m2 as $t) {
                $tag = strtolower($t[1]);
                if ($tag === 'a') continue; // (1)で処理済み
                $id = trim($t[2]);
                if ($id === '' || isset($seen_ids[strtolower($id)])) continue;
                $seen_ids[strtolower($id)] = true;

                // 表示テキスト: コンテナ要素は閉じタグまでの中身、area/img等の空要素は alt か title
                $text = '';
                if (in_array($tag, $void_tags, true)) {
                    if (preg_match('/\b(?:alt|title)=["\']([^"\']*)["\']/i', $t[0], $am)) $text = trim($am[1]);
                } else {
                    $pos = strpos($html, $t[0]);
                    if ($pos !== false) {
                        $rest = substr($html, $pos + strlen($t[0]), 4000);
                        $end  = stripos($rest, '</' . $tag);
                        if ($end !== false) $text = trim(str_replace(['<!--', '-->'], '', wp_strip_all_tags(substr($rest, 0, $end))));
                    }
                }
                if (mb_strlen($text) > 40) $text = mb_substr($text, 0, 40) . '…';

                $links[] = [
                    'href' => '',
                    'id'   => $id,
                    'text' => $text,
                    'host' => '',
                ];
            }
        }

        return $links;
    }

    /**
     * HTML内のJSクリック処理（getElementById('btNNN') … 'https://….html?…'）から
     * bt ID → リンク先URL の対応を抜き出し、bt対応表（alm_bt_map）へマージする。
     * 既存エントリと同じ値なら書き込まない。追加・更新した件数を返す。
     */
    public function merge_bt_map_from_html($html) {
        if (!preg_match_all('/getElementById\(\s*[\'"](bt\d+)[\'"]\s*\)/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return 0;
        }
        $map = get_option(self::BT_MAP_OPTION, []);
        if (!is_array($map)) $map = [];
        $changed = 0;
        foreach ($m[1] as $i => $hit) {
            $bt  = strtolower($hit[0]);
            $off = $m[0][$i][1];
            // 対応する click ハンドラ内の遷移先URL（直後600文字以内の最初の .html URL）
            $seg = substr($html, $off, 600);
            if (!preg_match('/[\'"](https?:\/\/[^\'"]+?\.html[^\'"]*)[\'"]/i', $seg, $um)) continue;
            $url = $um[1];
            if (!isset($map[$bt]) || $map[$bt] !== $url) {
                $map[$bt] = $url;
                $changed++;
            }
        }
        if ($changed > 0) update_option(self::BT_MAP_OPTION, $map, false);
        return $changed;
    }

    /* ---------- 外部ツール連携（コネクタ）用：情報を渡すだけのフック ---------- */

    /** その投稿の案件フル（values含む）を返す */
    public function connector_get_campaigns($def, $post_id) {
        return $this->get_campaigns((int) $post_id);
    }
    /** 送られてきた案件データをプラグインと同じルールでサニタイズして返す */
    public function connector_clean_campaigns($def, $raw) {
        return $this->clean_campaigns_input(is_array($raw) ? $raw : []);
    }
    /** bt対応表（alm_bt_map）を返す */
    public function connector_bt_map($def) {
        $m = get_option(self::BT_MAP_OPTION, []);
        return is_array($m) ? $m : [];
    }
    /** 判定方式（bt / keyword）を返す */
    public function connector_match_type($def) {
        return $this->get_match_type();
    }
    /** 記事内リンク一括取得（ajax_scan_links のコアを nonce/echo なしで実行） */
    public function connector_scan_links($def, $post_id) {
        return $this->scan_links_data((int) $post_id);
    }

    /** 記事内リンクをスキャンして配列で返す（ajax_scan_links と同じロジック） */
    public function scan_links_data($post_id) {
        $post = get_post($post_id);
        if (!$post) return ['links' => [], 'permalink' => '', 'slug' => ''];
        $base = get_permalink($post) ?: home_url('/');
        $links = [];
        if ($post->post_status === 'publish') {
            $html = $this->fetch_body($base, 10);
            if ($html !== null) {
                $links = $this->extract_links_from_html($html, $base);
                // 記事ページに埋め込まれたJS（bt→URLのクリック処理）から対応表を自動マージ。
                // 記事ごとにJSが埋め込まれるサイトでも、スキャンするだけで対応表が育つ
                $this->merge_bt_map_from_html($html);
            }
        }
        if (empty($links)) {
            $content = $post->post_content;
            try {
                if (function_exists('do_blocks')) $content = do_blocks($content);
                $content = do_shortcode($content);
            } catch (\Throwable $e) {
                $content = $post->post_content;
            }
            $links = $this->extract_links_from_html($content, $base);
        }
        return ['links' => $links, 'permalink' => $base, 'slug' => $post->post_name];
    }

    /** Ajax: メタボックス内の「保存」ボタン */
    /**
     * 送信された案件データを取得する。
     * 優先: alm_campaigns_json（案件を1個のJSONにまとめたもの）。
     * これにより、入力欄が数百個でも送信変数は1個で済み、PHPの max_input_vars 制限
     * （既定1000を超えると末尾が黙って捨てられる）を回避できる。
     * 旧形式（alm_campaigns[...] のバラバラ入力欄）にもフォールバック対応。
     */
    private function read_campaigns_from_request() {
        if (isset($_POST['alm_campaigns_json'])) {
            $json = wp_unslash($_POST['alm_campaigns_json']);
            $decoded = json_decode($json, true);
            if (is_array($decoded)) return $decoded;
        }
        if (isset($_POST['alm_campaigns']) && is_array($_POST['alm_campaigns'])) {
            return wp_unslash($_POST['alm_campaigns']);
        }
        return [];
    }

    public function ajax_quick_save() {
        check_ajax_referer(self::NONCE_KEY, 'nonce');
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['msg' => '権限がありません'], 403);
        }

        $raw = $this->read_campaigns_from_request();
        $clean = $this->clean_campaigns_input($raw);

        if (empty($clean)) {
            delete_post_meta($post_id, self::META_KEY);
        } else {
            update_post_meta($post_id, self::META_KEY, $clean);
        }
        // クイック保存は通常のsave_postを経由しないため、ページキャッシュを明示的に破棄する
        $this->purge_post_cache($post_id);
        wp_send_json_success(['count' => count($clean)]);
    }

    /* ---------- 保存 ---------- */

    public function save_meta($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id))                return; // リビジョン/autosaveはここで除外
        if (wp_is_post_autosave($post_id))                return;
        if (!in_array($post->post_type, self::POST_TYPES, true)) return;
        if (!isset($_POST[self::NONCE_KEY]) ||
            !wp_verify_nonce($_POST[self::NONCE_KEY], self::NONCE_KEY)) return;
        if (!current_user_can('edit_post', $post_id))     return;

        $raw = $this->read_campaigns_from_request();
        $clean = $this->clean_campaigns_input($raw);

        if (empty($clean)) {
            delete_post_meta($post_id, self::META_KEY);
        } else {
            update_post_meta($post_id, self::META_KEY, $clean);
        }
        $this->purge_post_cache($post_id);
    }

    /**
     * 案件設定の変更を即時反映させるため、主要キャッシュプラグイン/サーバーキャッシュの
     * 当該投稿ぶんを破棄する。非表示用 <style id="alm-hide"> はページHTMLに埋め込まれるため、
     * ページキャッシュが残っていると古い状態（消したいbtが入っていない等）が配信され続ける。
     */
    private function purge_post_cache($post_id) {
        $post_id = (int) $post_id;
        if (!$post_id) return;

        clean_post_cache($post_id);                                                   // WPコア(オブジェクトキャッシュ)

        if (function_exists('rocket_clean_post'))           rocket_clean_post($post_id);            // WP Rocket
        if (function_exists('w3tc_flush_post'))             w3tc_flush_post($post_id);              // W3 Total Cache
        if (function_exists('wp_cache_post_change'))        wp_cache_post_change($post_id);         // WP Super Cache
        if (function_exists('wpfc_clear_post_cache_by_id')) wpfc_clear_post_cache_by_id($post_id);  // WP Fastest Cache
        if (function_exists('sg_cachepress_purge_cache'))   sg_cachepress_purge_cache(get_permalink($post_id)); // SiteGround

        // フック型(発火しても該当プラグインが無ければ無害)
        do_action('litespeed_purge_post', $post_id);  // LiteSpeed Cache
        do_action('cache_enabler_clear_page_cache_by_post', $post_id); // Cache Enabler
    }

    /**
     * リビジョン/autosave保存時にも同じフォームデータをコピーする
     * （これでプレビューボタン押下時にもデータが残る）
     */
    public function save_revision_meta_via_post($post_id, $post, $update) {
        // リビジョンかautosaveの時のみ処理
        $parent_id = wp_is_post_revision($post_id);
        if (!$parent_id) return;

        $parent = get_post($parent_id);
        if (!$parent || !in_array($parent->post_type, self::POST_TYPES, true)) return;

        if (!isset($_POST[self::NONCE_KEY]) ||
            !wp_verify_nonce($_POST[self::NONCE_KEY], self::NONCE_KEY)) return;

        $raw = $this->read_campaigns_from_request();
        $clean = $this->clean_campaigns_input($raw);

        if (!empty($clean)) {
            update_metadata('post', $post_id, self::META_KEY, $clean);
        } else {
            delete_metadata('post', $post_id, self::META_KEY);
        }
    }

    /** プレビュー時：親投稿のメタ取得をautosave/リビジョン側のメタに差し替える */
    public function filter_preview_meta($value, $object_id, $meta_key, $single) {
        if ($meta_key !== self::META_KEY) return $value;
        if (!is_preview()) return $value;

        // 無限ループ防止：このフィルター内で再帰するのでいったん外す
        static $in_filter = false;
        if ($in_filter) return $value;

        // 現在のユーザーのautosaveを最優先
        $autosave = null;
        if (function_exists('wp_get_post_autosave')) {
            $user_id = get_current_user_id();
            if ($user_id) {
                $autosave = wp_get_post_autosave($object_id, $user_id);
            }
            if (!$autosave) {
                $autosave = wp_get_post_autosave($object_id);
            }
        }

        $source_id = 0;
        if ($autosave) {
            $source_id = $autosave->ID;
        } else {
            // autosaveが無ければ最新リビジョンを使用
            $revisions = wp_get_post_revisions($object_id, ['numberposts' => 1]);
            if (!empty($revisions)) {
                $rev = array_shift($revisions);
                $source_id = $rev->ID;
            }
        }
        if (!$source_id) return $value;

        $in_filter = true;
        // 生のmeta_valueを取得（シリアライズ済み文字列になっている可能性を考慮して $single=true で取得）
        $meta = get_metadata('post', $source_id, self::META_KEY, true);
        $in_filter = false;

        if ($meta === '' || $meta === [] || $meta === false || $meta === null) return $value;

        // WPのget_metadataフィルターは「常に配列で包んだ形」で返す必要がある
        // （$single判定は呼び出し元が [0] を取り出すかどうかの違いだけ）
        // さらに中身はシリアライズされた形式を期待されるケースもあるため maybe_serialize
        return [maybe_serialize($meta)];
    }

    /** save_metaとrevisionで共用する入力クリーニング */
    private function clean_campaigns_input(array $raw) {
        $match = $this->get_match_type();
        $clean = [];
        foreach ($raw as $camp) {
            $name = isset($camp['name']) ? sanitize_text_field($camp['name']) : '';
            $raw_vals = isset($camp['values']) && is_array($camp['values']) ? $camp['values'] : [];

            // 案件全体の旧フォーマット代替テキスト（既定値）— 互換読込用
            $legacy_replacement = isset($camp['replacement']) ? wp_kses_post(trim($camp['replacement'])) : '';
            // 案件全体の旧フォーマット親要素設定 — 互換読込用
            $legacy_parent = !empty($camp['parent']);
            $legacy_psels  = [];
            if (!empty($camp['parent_selectors']) && is_array($camp['parent_selectors'])) {
                foreach ($camp['parent_selectors'] as $ps) {
                    $ps = trim(sanitize_text_field($ps));
                    if ($ps !== '' && !in_array($ps, $legacy_psels, true)) {
                        $legacy_psels[] = $ps;
                    }
                }
            } elseif (!empty($camp['parent_selector'])) {
                $ps = trim(sanitize_text_field($camp['parent_selector']));
                if ($ps !== '') $legacy_psels[] = $ps;
            }

            $vals = [];
            $seen_bt = [];
            foreach ($raw_vals as $v) {
                if (is_array($v)) {
                    $bt_raw  = isset($v['bt'])          ? sanitize_text_field($v['bt'])         : '';
                    $rep_raw = isset($v['replacement']) ? wp_kses_post(trim($v['replacement'])) : '';
                    $bt_parent = !empty($v['parent']);
                    $bt_psels  = [];
                    if (!empty($v['parent_selectors']) && is_array($v['parent_selectors'])) {
                        foreach ($v['parent_selectors'] as $ps) {
                            $ps = trim(sanitize_text_field($ps));
                            if ($ps !== '' && !in_array($ps, $bt_psels, true)) $bt_psels[] = $ps;
                        }
                    }
                } else {
                    $bt_raw    = sanitize_text_field($v);
                    $rep_raw   = '';
                    $bt_parent = false;
                    $bt_psels  = [];
                }

                if ($match === 'bt') {
                    $bt_raw = $this->normalize_bt($bt_raw);
                } else {
                    $bt_raw = trim($bt_raw);
                }
                if ($bt_raw === '') continue;
                if (isset($seen_bt[$bt_raw])) continue; // 重複
                $seen_bt[$bt_raw] = true;

                // 旧フォーマットからの引き継ぎ：bt個別が空なら案件全体の値を使う
                if ($rep_raw === '' && $legacy_replacement !== '') $rep_raw = $legacy_replacement;
                if (!$bt_parent && $legacy_parent) $bt_parent = true;
                if (empty($bt_psels) && !empty($legacy_psels)) $bt_psels = $legacy_psels;

                // parent=ONでもセレクタが空ならparentをOFFにする
                if ($bt_parent && empty($bt_psels)) $bt_parent = false;

                $vals[] = [
                    'bt'               => $bt_raw,
                    'replacement'      => $rep_raw,
                    'parent'           => $bt_parent,
                    'parent_selectors' => $bt_psels,
                ];
            }

            if (empty($vals)) continue;

            $clean[] = [
                'name'      => $name !== '' ? $name : '(無題)',
                'values'    => $vals,
                'disabled'  => !empty($camp['disabled']),
                'collapsed' => !empty($camp['collapsed']),
            ];
        }
        return $clean;
    }

    /* ---------- フロント出力 ---------- */

    public function output_frontend_hide() {
        if (!is_singular(self::POST_TYPES)) return;
        $post_id = get_queried_object_id();
        if (!$post_id) return;

        $campaigns = $this->get_campaigns($post_id);
        $match = $this->get_match_type();

        // 診断: 管理者が ?alm_debug=1 付きで開くと、この投稿について実際に読み込んだ
        // 案件データ（投稿ID・コメントアウト状態・bt一覧）をHTMLコメントで出力する。
        if (isset($_GET['alm_debug']) && current_user_can('manage_options')) {
            $dbg = [
                'post_id'    => $post_id,
                'is_preview' => is_preview() ? 1 : 0,
                'match'      => $match,
                'campaign_count' => is_array($campaigns) ? count($campaigns) : 0,
                'campaigns'  => [],
            ];
            foreach ((array) $campaigns as $c) {
                $bts = [];
                if (!empty($c['values']) && is_array($c['values'])) {
                    foreach ($c['values'] as $v) {
                        $it = $this->normalize_value_item($v);
                        if ($it['bt'] !== '') $bts[] = $it['bt'];
                    }
                }
                $dbg['campaigns'][] = [
                    'name'     => isset($c['name']) ? $c['name'] : '',
                    'disabled' => !empty($c['disabled']) ? 1 : 0,
                    'bts'      => $bts,
                ];
            }
            echo "\n<!-- ALM_DEBUG " . wp_json_encode($dbg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . " -->\n";
        }

        $all_selectors = [];          // CSSで一括非表示用
        $replacements  = [];          // [{selectors: [...], html: '...'}] 代替テキスト挿入用

        // 旧データ互換用: 案件全体の親モード/親セレクタ/代替テキストを読む
        foreach ($campaigns as $camp) {
            if (empty($camp['disabled'])) continue;

            $legacy_camp_parent  = !empty($camp['parent']);
            $legacy_camp_psels   = [];
            if (!empty($camp['parent_selectors']) && is_array($camp['parent_selectors'])) {
                $legacy_camp_psels = $camp['parent_selectors'];
            } elseif (!empty($camp['parent_selector'])) {
                $legacy_camp_psels = [$camp['parent_selector']];
            }
            $legacy_camp_rep = isset($camp['replacement']) ? (string)$camp['replacement'] : '';

            if (empty($camp['values']) || !is_array($camp['values'])) continue;

            foreach ($camp['values'] as $v) {
                $item    = $this->normalize_value_item($v);
                $bt_val  = $item['bt'];
                $rep_val = $item['replacement'];
                $bt_parent = $item['parent'];
                $bt_psels  = $item['parent_selectors'];
                if ($bt_val === '') continue;

                // 旧データ互換: bt個別に設定がない場合のみ案件全体を引き継ぐ
                if ($rep_val === '' && $legacy_camp_rep !== '') $rep_val = $legacy_camp_rep;
                if (!$bt_parent && $legacy_camp_parent && empty($bt_psels) && !empty($legacy_camp_psels)) {
                    $bt_parent = true;
                    $bt_psels  = $legacy_camp_psels;
                }

                // セレクタを決定
                $bt_selectors = [];
                if ($bt_parent && !empty($bt_psels)) {
                    // 親要素モード
                    foreach ($bt_psels as $ps) {
                        $ps = trim((string)$ps);
                        if ($ps === '') continue;
                        $clean = $this->sanitize_css_selector($ps);
                        if ($clean !== '') $bt_selectors[] = $clean;
                    }
                } else {
                    // 通常モード
                    if ($match === 'bt') {
                        $id = $this->normalize_bt($bt_val);
                        if ($id === '') continue;
                        $id_safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $id);
                        $bt_selectors[] = '#' . $id_safe;
                    } else {
                        $kw = $this->escape_css_attr_value($bt_val);
                        if ($kw === '') continue;
                        // 「?」を含むキーワードはクエリ（リファラ）込みで特定の1リンクを狙う指定。
                        // 部分一致(*=)だと「?slug_top」が「?slug_top_txt」まで巻き込むため末尾一致($=)にする。
                        $op = (strpos($bt_val, '?') !== false) ? '$=' : '*=';
                        $bt_selectors[] = 'a[href' . $op . '"' . $kw . '"]';
                    }
                }

                if (empty($bt_selectors)) continue;

                $all_selectors = array_merge($all_selectors, $bt_selectors);

                if ($rep_val !== '') {
                    $replacements[] = [
                        'selectors' => $bt_selectors,
                        'html'      => $rep_val,
                    ];
                }
            }
        }

        if (empty($all_selectors)) return;

        $all_selectors = array_values(array_filter(array_unique($all_selectors), function ($s) {
            return $s !== '';
        }));
        if (empty($all_selectors)) return;

        $sel = implode(',', $all_selectors);
        $sel = str_replace('</', '<\/', $sel);

        echo "\n<style id=\"alm-hide\">{$sel}{display:none !important;}</style>\n";

        // 代替テキストの挿入用JS
        if (!empty($replacements)) {
            $json = wp_json_encode($replacements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            ?>
<script id="alm-replacement">
(function(){
  var rules = <?php echo $json; ?>;
  function insertReplacements(){
    rules.forEach(function(rule){
      var sel = rule.selectors.join(',');
      var targets;
      try { targets = document.querySelectorAll(sel); }
      catch(e){ return; }
      targets.forEach(function(el){
        // 既に代替テキストが挿入済みならスキップ
        if (el.nextSibling && el.nextSibling.nodeType === 1 &&
            el.nextSibling.classList && el.nextSibling.classList.contains('alm-replacement-text')) return;
        var span = document.createElement('span');
        span.className = 'alm-replacement-text';
        span.innerHTML = rule.html;
        el.parentNode.insertBefore(span, el.nextSibling);
      });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', insertReplacements);
  } else {
    insertReplacements();
  }
})();
</script>
            <?php
        }
    }

    /**
     * CSSセレクタとして安全な文字だけ通す（改行・{}・</などは除去）
     * ユーザー入力をそのままstyleに出すのでXSS対策として重要
     */
    private function sanitize_css_selector($s) {
        // 制御文字除去
        $s = preg_replace('/[\x00-\x1F\x7F]/', '', $s);
        // styleタグを閉じられないように
        $s = str_replace('</', '', $s);
        // {, }, は禁止（CSS構文を壊す）
        $s = str_replace(['{', '}'], '', $s);
        return trim($s);
    }

    /* ---------- utils ---------- */

    public function get_campaigns($post_id) {
        // プレビュー時は直接autosave/リビジョンのメタを読む（フィルター経由より確実）
        if (is_preview()) {
            $source_id = $this->get_preview_source_id($post_id);
            if ($source_id) {
                $data = get_metadata('post', $source_id, self::META_KEY, true);
                if (is_array($data) && !empty($data)) return $data;
            }
        }
        $data = get_post_meta($post_id, self::META_KEY, true);
        return is_array($data) ? $data : [];
    }

    /** プレビュー用のデータソース（autosave優先、なければ最新リビジョン）のIDを返す */
    private function get_preview_source_id($post_id) {
        $autosave = null;
        if (function_exists('wp_get_post_autosave')) {
            $user_id = get_current_user_id();
            if ($user_id) {
                $autosave = wp_get_post_autosave($post_id, $user_id);
            }
            if (!$autosave) {
                $autosave = wp_get_post_autosave($post_id);
            }
        }
        if ($autosave) return $autosave->ID;

        $revisions = wp_get_post_revisions($post_id, ['numberposts' => 1]);
        if (!empty($revisions)) {
            $rev = array_shift($revisions);
            return $rev->ID;
        }
        return 0;
    }

    public function normalize_bt($raw) {
        $s = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$raw);
        if ($s === '') return '';
        if (stripos($s, 'bt') !== 0) $s = 'bt' . $s;
        return $s;
    }

    private function escape_css_attr_value($s) {
        $s = preg_replace('/[\x00-\x1F\x7F]/', '', $s);
        $s = str_replace('\\', '\\\\', $s);
        $s = str_replace('"', '\\"', $s);
        return $s;
    }

    /** 値を {bt, replacement, parent, parent_selectors} 形式に正規化（旧フォーマットの単純文字列にも対応） */
    public function normalize_value_item($v) {
        if (is_array($v)) {
            $parent_selectors = [];
            if (!empty($v['parent_selectors']) && is_array($v['parent_selectors'])) {
                foreach ($v['parent_selectors'] as $ps) {
                    $ps = trim((string)$ps);
                    if ($ps !== '') $parent_selectors[] = $ps;
                }
            }
            return [
                'bt'               => isset($v['bt']) ? (string)$v['bt'] : '',
                'replacement'      => isset($v['replacement']) ? (string)$v['replacement'] : '',
                'parent'           => !empty($v['parent']),
                'parent_selectors' => $parent_selectors,
            ];
        }
        // 旧: 単純文字列はそのままbtとして扱う
        return [
            'bt'               => (string)$v,
            'replacement'      => '',
            'parent'           => false,
            'parent_selectors' => [],
        ];
    }

    /** URLからグループ名を抽出（パスの最後のファイル名 .html を除いた部分） */
    public function group_name_from_url($url) {
        $parts = wp_parse_url($url);
        if (empty($parts['path'])) return '';
        $path = $parts['path'];
        // パス最後のセグメントを取得
        $segments = array_filter(explode('/', $path));
        $last = end($segments);
        if (!$last) return '';
        // .html / .htm / .php などの拡張子を除去
        $name = preg_replace('/\.(html?|php|aspx?|jsp)$/i', '', $last);
        $name = trim($name);
        return $name === '' ? '' : strtolower($name);
    }

    /** bt対応表をグループ化して返す: ['aiful' => ['bt535'=>'url', 'bt712'=>'url'], ...] */
    public function group_bt_map($bt_map) {
        $groups = [];
        if (!is_array($bt_map)) return $groups;
        foreach ($bt_map as $bt => $url) {
            $g = $this->group_name_from_url($url);
            if ($g === '') $g = '(その他)';
            if (!isset($groups[$g])) $groups[$g] = [];
            $groups[$g][$bt] = $url;
        }
        ksort($groups);
        return $groups;
    }

    /** URLを短く表示（ホスト名＋パス先頭だけ） */
    private function short_url($url) {
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) return $url;
        $host = $parts['host'];
        $path = isset($parts['path']) ? $parts['path'] : '';
        if (mb_strlen($path) > 30) $path = mb_substr($path, 0, 30) . '…';
        return $host . $path;
    }
}

new Ad_Link_Manager();

/* ---------- GitHub リリースからの自動更新 ----------
 * WP標準の Update URI 機構（WP5.8+）。プラグインヘッダの Update URI が github.com のため、
 * 更新チェック時に update_plugins_github.com フィルタが呼ばれる。
 * リポジトリ terasakisd/alm-plugins のリリースから、タグ「ad-link-manager-<version>」かつ
 * アセット「ad-link-manager.zip」を探し、現行より新しければ WP の更新通知に載せる。
 */
add_filter('update_plugins_github.com', function ($update, $plugin_data, $plugin_file) {
    if ($plugin_file !== plugin_basename(__FILE__)) return $update;

    $cache_key = 'ad_link_manager_gh_update';
    // 「更新 → もう一度確認する」(force-check) のときはキャッシュを使わず即時に見にいく
    $force = is_admin() && !empty($_GET['force-check']);
    $info = $force ? false : get_transient($cache_key);
    if ($info === false) {
        $info = ['version' => '', 'package' => ''];
        $res = wp_remote_get(
            'https://api.github.com/repos/terasakisd/alm-plugins/releases?per_page=20',
            ['timeout' => 10, 'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'WordPress; ' . home_url('/')]]
        );
        if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
            $releases = json_decode(wp_remote_retrieve_body($res), true);
            foreach ((array) $releases as $r) {
                if (!empty($r['draft']) || !empty($r['prerelease'])) continue;
                $tag = (string) ($r['tag_name'] ?? '');
                if (strpos($tag, 'ad-link-manager-') !== 0) continue; // 他プラグインのリリースは無視
                $ver = substr($tag, strlen('ad-link-manager-'));
                foreach ((array) ($r['assets'] ?? []) as $a) {
                    if (($a['name'] ?? '') === 'ad-link-manager.zip') {
                        $info = ['version' => $ver, 'package' => (string) $a['browser_download_url']];
                        break 2;
                    }
                }
            }
        }
        // 6時間キャッシュ（GitHub APIの未認証レート制限対策）
        set_transient($cache_key, $info, 6 * HOUR_IN_SECONDS);
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
