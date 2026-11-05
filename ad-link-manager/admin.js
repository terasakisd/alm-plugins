jQuery(function ($) {
    const $wrap = $('.alm-wrap');
    if (!$wrap.length) return;

    const $container = $('#alm-campaigns');

    function reindex() {
        $container.find('.alm-campaign').each(function (ci) {
            const $camp = $(this);
            $camp.attr('data-ci', ci);

            // 値行のVI振り直し
            $camp.find('.alm-value-row').each(function (vi) {
                $(this).attr('data-vi', vi);
                $(this).find('input').each(function () {
                    const n = $(this).attr('name');
                    if (!n) return;
                    let newName = n.replace(/alm_campaigns\[[^\]]+\]/, 'alm_campaigns[' + ci + ']');
                    newName = newName.replace(/\[values\]\[[^\]]+\]/, '[values][' + vi + ']');
                    $(this).attr('name', newName);
                });
            });

            // 案件レベルのinput（案件名・disabled・collapsed-state）
            $camp.find('> .alm-campaign-head input').each(function () {
                const n = $(this).attr('name');
                if (!n) return;
                $(this).attr('name', n.replace(/alm_campaigns\[[^\]]+\]/, 'alm_campaigns[' + ci + ']'));
            });
        });
    }

    // 通常の「更新／公開」ボタンでの送信時：案件を1個のJSONにまとめ、個別の入力欄は
    // 送信対象から外す(disable)。これで通常保存でも max_input_vars(入力欄数の上限)を回避する。
    // （バラバラの数百個の入力欄のまま送ると、末尾の案件/btが黙って捨てられるため）
    $(document).on('submit', 'form#post', function () {
        reindex();
        try {
            const json = JSON.stringify(collectCampaigns());
            // 個別入力欄(alm_campaigns[...])を送信対象から外す
            $container.find('[name^="alm_campaigns["]').prop('disabled', true);
            // JSON を1個の hidden で送る
            let $hidden = $('#alm-campaigns-json-field');
            if (!$hidden.length) {
                $hidden = $('<input type="hidden" id="alm-campaigns-json-field" name="alm_campaigns_json">');
                $container.append($hidden);
            }
            $hidden.prop('disabled', false).val(json);
        } catch (e) { /* 失敗時は従来通り個別入力欄で送信される */ }
    });

    // メタボックスの全案件を、PHPが期待する構造のまま JS オブジェクト配列にまとめる。
    // これを JSON 1個で送ることで max_input_vars(入力欄数の上限)を回避する。
    function collectCampaigns() {
        const campaigns = [];
        $container.find('.alm-campaign').each(function () {
            const $c = $(this);
            const camp = {
                name: $c.find('.alm-campaign-name').first().val() || '',
                disabled: $c.find('input[name$="[disabled]"]').is(':checked') ? 1 : 0,
                collapsed: $c.find('.alm-collapsed-state').first().val() === '1' ? 1 : 0,
                values: []
            };
            $c.find('.alm-value-row').each(function () {
                const $r = $(this);
                const bt = ($r.find('.alm-value').first().val() || '').trim();
                const replacement = $r.find('.alm-value-replacement-input').first().val() || '';
                const parent = $r.find('.alm-parent-toggle-bt').is(':checked') ? 1 : 0;
                const parent_selectors = [];
                $r.find('.alm-parent-selector-bt').each(function () {
                    const v = ($(this).val() || '').trim();
                    if (v) parent_selectors.push(v);
                });
                camp.values.push({ bt: bt, replacement: replacement, parent: parent, parent_selectors: parent_selectors });
            });
            campaigns.push(camp);
        });
        return campaigns;
    }

    function ensureNotEmpty() {
        if ($container.find('.alm-campaign').length === 0) {
            if ($container.find('.alm-empty').length === 0) {
                $container.html('<div class="alm-empty">まだ案件が登録されていません。</div>');
            }
        } else {
            $container.find('.alm-empty').remove();
        }
    }

    // 案件追加
    $('#alm-add-campaign').on('click', function () {
        $container.find('.alm-empty').remove();
        const nextIndex = $container.find('.alm-campaign').length;
        const tmpl = $('#alm-campaign-template').html().replace(/__INDEX__/g, nextIndex);
        const $new = $($.parseHTML(tmpl));
        $container.append($new);
        reindex(); // 追加直後に全体を振り直し、インデックス衝突（保存時の上書き消失）を防ぐ
        $new.find('.alm-campaign-name').focus();
    });

    // 案件削除
    $container.on('click', '.alm-delete-campaign', function () {
        if (!confirm('この案件を削除しますか？')) return;
        $(this).closest('.alm-campaign').remove();
        reindex();
        ensureNotEmpty();
    });

    // 折りたたみトグル
    $container.on('click', '.alm-toggle-collapse', function (e) {
        e.preventDefault();
        const $camp = $(this).closest('.alm-campaign');
        const collapsed = !$camp.hasClass('is-collapsed');
        $camp.toggleClass('is-collapsed', collapsed);
        $camp.find('.alm-caret').first().text(collapsed ? '▶' : '▼');
        $camp.find('.alm-collapsed-state').val(collapsed ? '1' : '0');
    });

    // 案件名クリックでも開閉（テキストエリア外をダブルクリックなどではなく、ヘッダー余白のクリック）
    // → 入力欄を誤操作しないよう、トグルはアイコンクリックのみに留める

    // bt値の変更でサマリー件数を更新
    function updateSummary($camp) {
        let count = 0;
        $camp.find('.alm-value').each(function () {
            if ($(this).val().trim() !== '') count++;
        });
        $camp.find('.alm-campaign-summary').first().text(count + '件');
    }
    $container.on('input change', '.alm-value', function () {
        updateSummary($(this).closest('.alm-campaign'));
    });

    // 値の行を追加
    $container.on('click', '.alm-add-value', function () {
        const $camp = $(this).closest('.alm-campaign');
        const ci = $camp.attr('data-ci');
        const $values = $camp.find('.alm-values');
        const nextVi = $values.find('.alm-value-row').length;
        const tmpl = $('#alm-value-template').html()
            .replace(/__CI__/g, ci)
            .replace(/__VI__/g, nextVi);
        const $row = $($.parseHTML(tmpl));
        $values.append($row);
        reindex(); // VI衝突（保存時の値の上書き消失）を防ぐ
        $row.find('.alm-value').focus();
        updateSummary($camp);
    });

    // 値の行を削除（最後の1行は残す）
    $container.on('click', '.alm-remove-value', function () {
        const $camp = $(this).closest('.alm-campaign');
        const $rows = $camp.find('.alm-value-row');
        if ($rows.length <= 1) {
            // 最後の1行は値だけクリア
            $camp.find('.alm-value').val('');
            $camp.find('.alm-value-replacement-input').val('');
            $camp.find('.alm-url-hint').empty();
            updateSummary($camp);
            return;
        }
        $(this).closest('.alm-value-row').remove();
        reindex();
        updateSummary($camp);
    });

    // bt入力時のURLヒント更新
    function shortenUrl(url) {
        try {
            const u = new URL(url);
            let path = u.pathname || '';
            if (path.length > 30) path = path.slice(0, 30) + '…';
            return u.host + path;
        } catch (e) { return url; }
    }
    function normalizeBt(v) {
        v = (v || '').replace(/[^A-Za-z0-9_\-]/g, '');
        if (!v) return '';
        if (!/^bt/i.test(v)) v = 'bt' + v;
        return v.toLowerCase();
    }
    $container.on('input change', '.alm-value', function () {
        if (ALM.matchType !== 'bt') return; // キーワードモードのヒント（リンクテキスト）は消さない
        const $row = $(this).closest('.alm-value-row');
        const $hint = $row.find('.alm-url-hint');
        if (!$hint.length || !ALM.btMap) return;
        const key = normalizeBt($(this).val());
        const url = key && ALM.btMap[key];
        if (url) {
            $hint.html('→ <a href="' + url + '" target="_blank" rel="noopener">' + shortenUrl(url) + '</a>');
        } else {
            $hint.empty();
        }
    });

    // bt行内の「親要素ごと消す」チェックボックス → セレクタ欄の表示切替
    $container.on('change', '.alm-parent-toggle-bt', function () {
        const $row = $(this).closest('.alm-value-row');
        const $section = $row.find('.alm-parent-selectors-bt');
        if ($(this).is(':checked')) {
            $section.show();
            $section.find('.alm-parent-selector-bt').first().focus();
        } else {
            $section.hide();
        }
    });

    // bt行内の親セレクタを追加
    $container.on('click', '.alm-add-parent-bt', function () {
        const $section = $(this).closest('.alm-parent-selectors-bt');
        const $row = $(this).closest('.alm-value-row');
        // name属性の参考用に、行内の他のparent_selectorsからnameを拝借
        const sampleName = $section.find('.alm-parent-selector-bt').first().attr('name')
            || 'alm_campaigns[0][values][0][parent_selectors][]';
        const $newRow = $(
            '<div class="alm-parent-row-bt">' +
                '<input type="text" name="' + sampleName + '" ' +
                       'class="regular-text alm-parent-selector-bt" placeholder=".btn-wrap">' +
                '<button type="button" class="button alm-remove-parent-bt" title="削除">×</button>' +
            '</div>'
        );
        // 「+ 親セレクタを追加」ボタンの直前に挿入
        $section.find('.alm-value-actions').first().before($newRow);
        $newRow.find('input').focus();
    });

    // bt行内の親セレクタを削除（最後の1行は値だけクリア）
    $container.on('click', '.alm-remove-parent-bt', function () {
        const $section = $(this).closest('.alm-parent-selectors-bt');
        const $rows = $section.find('.alm-parent-row-bt');
        if ($rows.length <= 1) {
            $section.find('.alm-parent-selector-bt').val('');
            return;
        }
        $(this).closest('.alm-parent-row-bt').remove();
    });

    // 「この設定を保存」ボタン
    const $status = $('#alm-status');
    function showStatus(msg, isError) {
        $status.text(msg).toggleClass('error', !!isError);
        if (!isError) setTimeout(function () {
            if ($status.text() === msg) $status.text('');
        }, 3000);
    }

    $('#alm-quick-save').on('click', function (e) {
        e.preventDefault();
        const $btn = $(this);
        $btn.prop('disabled', true);
        showStatus('保存中...');

        // 送信直前に必ず採番を振り直し、インデックス重複によるデータ消失を防ぐ
        reindex();

        // 案件を「1個のJSON」にまとめて送る。
        // バラバラの入力欄(数百個)で送るとPHPの max_input_vars(既定1000)で末尾が黙って捨てられるため、
        // 変数1個(JSON文字列)に集約してこの制限を回避する。
        const payload = {
            action: 'alm_quick_save',
            nonce: ALM.nonce,
            post_id: ALM.postId,
            alm_campaigns_json: JSON.stringify(collectCampaigns())
        };

        $.ajax({
            url: ALM.ajaxUrl,
            method: 'POST',
            data: payload,
            dataType: 'json'
        })
        .done(function (res) {
            if (res && res.success) {
                showStatus('保存しました（' + (res.data && res.data.count) + '件）');
            } else {
                const msg = (res && res.data && res.data.msg) ? res.data.msg : '保存に失敗しました';
                showStatus(msg, true);
                console.log('ALM save error:', res);
            }
        })
        .fail(function (xhr) {
            showStatus('通信エラー (HTTP ' + xhr.status + ')', true);
            console.log('ALM save fail:', xhr.status, xhr.responseText);
        })
        .always(function () { $btn.prop('disabled', false); });
    });

    // ========== 一括取り込み共通処理 ==========
    // groups = { 案件名: [値, ...] } をメタボックスに反映する。
    // normFn は重複判定用の正規化関数（btモード: normalizeBt / キーワードモード: trim）
    function importGroups(groups, normFn) {
        // 既存の案件名 → カード要素 のマップ
        const existingByName = {};
        $container.find('.alm-campaign').each(function () {
            const name = $(this).find('.alm-campaign-name').val().trim();
            if (name) existingByName[name] = $(this);
        });

        let createdCount = 0;
        let mergedCount  = 0;
        let addedCount   = 0;

        Object.keys(groups).forEach(function (gname) {
            const vals = groups[gname];
            if (!vals.length) return;

            let $camp;
            if (existingByName[gname]) {
                // 既存案件にマージ
                $camp = existingByName[gname];
                mergedCount++;
            } else {
                // 新規カードを作成
                $container.find('.alm-empty').remove();
                const nextIndex = $container.find('.alm-campaign').length;
                const tmpl = $('#alm-campaign-template').html().replace(/__INDEX__/g, nextIndex);
                $camp = $($.parseHTML(tmpl));
                $container.append($camp);
                $camp.find('.alm-campaign-name').val(gname);
                createdCount++;
            }

            // 既に入力されている値を取得（重複回避）
            const existingValues = {};
            $camp.find('.alm-value').each(function () {
                const v = normFn($(this).val());
                if (v) existingValues[v] = true;
            });

            // 空欄の最初の行を再利用するための準備
            const $values = $camp.find('.alm-values');
            const $emptyRows = $camp.find('.alm-value-row').filter(function () {
                return $(this).find('.alm-value').val().trim() === '';
            });

            const ci = $camp.attr('data-ci');

            vals.forEach(function (item) {
                // 値は文字列 or { value, hint } オブジェクト（hint = リンクテキスト等の補足表示）
                const val  = (item && typeof item === 'object') ? item.value : item;
                const hint = (item && typeof item === 'object') ? (item.hint || '') : '';
                const key = normFn(val);
                if (!key || existingValues[key]) return; // 重複スキップ
                existingValues[key] = true;

                // 空行があればそこに埋める、なければ新規追加
                let $row = $emptyRows.length ? $emptyRows.first() : null;
                if ($row && $row.length) {
                    $row.find('.alm-value').val(val).trigger('input');
                    $emptyRows.splice(0, 1); // 使った
                } else {
                    const nextVi = $values.find('.alm-value-row').length;
                    const rowTmpl = $('#alm-value-template').html()
                        .replace(/__CI__/g, ci)
                        .replace(/__VI__/g, nextVi);
                    $row = $($.parseHTML(rowTmpl));
                    $row.find('.alm-value').val(val);
                    $values.append($row);
                    $row.find('.alm-value').trigger('input'); // URLヒント反映
                }
                if (hint) $row.find('.alm-url-hint').first().text('→ ' + hint);
                addedCount++;
            });
        });

        // インデックス振り直し（CIとVI両方）
        reindex();

        // 全案件を折りたたみ状態に＆サマリー更新（一覧で確認しやすく）
        $container.find('.alm-campaign').each(function () {
            const $c = $(this);
            $c.addClass('is-collapsed');
            $c.find('.alm-caret').first().text('▶');
            $c.find('.alm-collapsed-state').val('1');
            updateSummary($c);
        });

        return { created: createdCount, merged: mergedCount, added: addedCount };
    }

    function showImportResult(r) {
        showStatus(
            '取り込み完了：新規' + r.created + '案件 / マージ' + r.merged + '案件 / 追加' + r.added + '件。「この設定を保存」を押してください。'
        );
    }

    // ========== JSから一括取り込み ==========
    $('#alm-import-from-js').on('click', function () {
        const btGroups = ALM.btGroups || {};
        const groupNames = Object.keys(btGroups);
        if (groupNames.length === 0) {
            showStatus('取り込めるグループがありません。先に設定でJSファイルを解析してください。', true);
            return;
        }

        const totalBts = groupNames.reduce(function (sum, n) {
            return sum + Object.keys(btGroups[n]).length;
        }, 0);
        const ok = confirm(
            'bt対応表から ' + groupNames.length + ' グループ（合計 ' + totalBts + '件）を取り込みます。\n' +
            '同名の案件がすでにある場合は、その案件にbt IDが追加されます（重複は除外）。\n\n' +
            '続行しますか？'
        );
        if (!ok) return;

        const groups = {};
        groupNames.forEach(function (n) { groups[n] = Object.keys(btGroups[n]); });
        showImportResult(importGroups(groups, normalizeBt));
    });

    // ========== 記事内のaタグリンクを一括取得 ==========
    $('#alm-scan-links').on('click', function () {
        const $btn = $(this);
        if (!ALM.postId) {
            showStatus('先に下書き保存してください', true);
            return;
        }
        $btn.prop('disabled', true);
        showStatus('記事内のリンクを取得中...');

        $.ajax({
            url: ALM.ajaxUrl,
            method: 'POST',
            data: { action: 'alm_scan_links', nonce: ALM.nonce, post_id: ALM.postId },
            dataType: 'json'
        })
        .done(function (res) {
            if (!res || !res.success) {
                showStatus((res && res.data && res.data.msg) || '取得に失敗しました', true);
                return;
            }
            const links = res.data.links || [];

            if (ALM.matchType === 'bt') {
                // btモード: id="bt123" 形式のリンクからbt IDを取り込む
                const ids = [];
                const seen = {};
                links.forEach(function (l) {
                    const id = (l.id || '').toLowerCase();
                    if (!/^bt\d+$/.test(id) || seen[id]) return;
                    seen[id] = true;
                    ids.push(id);
                });
                if (!ids.length) {
                    showStatus('記事内にbt ID付きリンクが見つかりませんでした', true);
                    return;
                }
                if (!confirm('記事内から bt ID付きリンク ' + ids.length + '件 を取り込みます。よろしいですか？')) return;
                showImportResult(importGroups({ '記事内リンク': ids }, normalizeBt));
            } else {
                // キーワードモード: 広告リンク＝「.html を含み、URL内に記事スラッグを含むリンク」を取り込む
                // 例: https://www.example.com/lp/acom.html?example-article_top_txt
                //     （acom.html = 案件 / クエリ部分に記事スラッグ）
                // スラッグ一致が0件の場合は、.htmlリンク全件を候補として確認ダイアログに出す
                const permalink = res.data.permalink || '';
                const slug = res.data.slug || '';

                function collectHtmlLinks(requireSlugMatch) {
                    const groups = {};
                    const seen = {};
                    links.forEach(function (l) {
                        if (!l.href) return;
                        if (l.href.indexOf('.html') === -1) return;
                        if (requireSlugMatch) {
                            const bySlug = slug && l.href.indexOf(slug) !== -1;
                            const byPermalink = permalink && l.href.indexOf(permalink) !== -1;
                            if (!bySlug && !byPermalink) return;
                        }
                        const noHash = l.href.split('#')[0];
                        const qpos  = noHash.indexOf('?');
                        const clean = qpos === -1 ? noHash : noHash.slice(0, qpos);
                        const query = qpos === -1 ? '' : noHash.slice(qpos + 1);
                        const file = clean.substring(clean.lastIndexOf('/') + 1); // 例: acom.html
                        if (!/\.html?$/i.test(file)) return;
                        // クエリ（リファラ）があれば「file?query」で1リンクずつ個別のキーワードにする。
                        // ?を含むキーワードはPHP側でhref末尾一致($=)として扱われ、リンク単位で表示/非表示できる。
                        const keyword = query ? file + '?' + query : file;
                        if (seen[keyword]) return;
                        seen[keyword] = true;
                        const name = file.replace(/\.html?$/i, '') || 'リンク';   // 案件名: acom
                        if (!groups[name]) groups[name] = [];
                        groups[name].push({ value: keyword, hint: l.text || '' });
                    });
                    return groups;
                }

                let groups = collectHtmlLinks(true);
                let names = Object.keys(groups);
                let fallback = false;
                if (!names.length) {
                    groups = collectHtmlLinks(false);
                    names = Object.keys(groups);
                    fallback = true;
                }
                const total = names.reduce(function (s, n) { return s + groups[n].length; }, 0);
                if (!total) {
                    showStatus('記事内に.htmlリンクが見つかりませんでした', true);
                    return;
                }
                let msg = fallback
                    ? '記事スラッグに一致する広告リンクは見つかりませんでした。\n代わりにページ内の.htmlリンク ' + total + '件 を取り込みます（?以降のリファラで1リンクずつ個別に登録されます）：\n\n'
                    : '記事内から広告リンク ' + total + '件 を取り込みます（?以降のリファラで1リンクずつ個別に登録されます）：\n\n';
                names.forEach(function (n) { msg += '・' + n + '（' + groups[n].length + '件）\n'; });
                if (fallback) msg += '\n※広告でないものが混ざっている場合は、取り込み後に不要な案件を削除してください。';
                msg += '\n続行しますか？';
                if (!confirm(msg)) return;
                showImportResult(importGroups(groups, function (v) { return (v || '').trim(); }));
            }
        })
        .fail(function (xhr) {
            showStatus('通信エラー (HTTP ' + xhr.status + ')', true);
        })
        .always(function () { $btn.prop('disabled', false); });
    });
});
