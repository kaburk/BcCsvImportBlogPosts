<?php

/**
 * BcCsvImportBlogPosts 設定
 *
 * 独自のメニューキーと独自コントローラーを使用するため、BcCsvImportCore のメニューキーとは
 * 別のキー（BcCsvImportBlogPosts）でメニューを登録する。
 * これにより複数のインポートプラグインを同時有効化しても競合しない。
 */
return [
    'BcApp' => [
        'adminNavigation' => [
            'Contents' => [
                'BcCsvImportBlogPosts' => [
                    'title' => __d('baser_core', 'ブログ記事CSVインポート'),
                    'url' => [
                        'Admin' => true,
                        'plugin' => 'BcCsvImportBlogPosts',
                        'controller' => 'blog_posts_csv_imports',
                        'action' => 'index',
                    ],
                ],
            ],
        ],
    ],
    'BcCsvImportBlogPosts' => [
        // --- UI 設定 ---
        // コントローラーの resolveUiSettings() がこのキーを優先して読む
        'showImportStrategySelect' => false,
        'defaultImportStrategy'    => 'append',
        'showDuplicateModeSelect'  => false,
        'defaultDuplicateMode'     => 'skip',

        // --- サービス設定 ---
        // CSV の「カテゴリ名」列に指定したカテゴリが DB に存在しなかった場合の挙動
        //   'error'  : その行をエラーとしてスキップする（推奨: 意図しないカテゴリ作成を防ぐ）
        //   'create' : 同名のカテゴリを自動作成してインポートを続行する
        'blogCategoryNotFound'     => 'create',

        // CSV の「タグ」列に指定したタグが DB に存在しなかった場合の挙動
        //   'ignore' : 存在しないタグは無視し、存在するタグだけを付けて続行する（推奨）
        //   'error'  : 存在しないタグが1件でもあれば、その行をエラーとしてスキップする
        //   'create' : 存在しないタグを自動作成してインポートを続行する
        'blogTagNotFound'          => 'create',
    ],
];
