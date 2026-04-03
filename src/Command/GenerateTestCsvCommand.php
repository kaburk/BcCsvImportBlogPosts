<?php
declare(strict_types=1);

namespace BcCsvImportBlogPosts\Command;

use BcCsvImportCore\Command\AbstractGenerateTestCsvCommand;
use BcCsvImportCore\Service\CsvImportServiceInterface;
use BcCsvImportBlogPosts\Service\BlogPostsCsvImportService;

/**
 * GenerateTestCsvCommand
 *
 * BlogPosts テスト用CSVファイルを生成する CakePHP コンソールコマンド。
 * CSVヘッダは BlogPostsCsvImportService::getColumnMap() から自動取得します。
 *
 * 使い方（プロジェクトルートから実行）:
 *   bin/cake BcCsvImportBlogPosts.generate_test_csv
 */
class GenerateTestCsvCommand extends AbstractGenerateTestCsvCommand
{

    public static function defaultName(): string
    {
        return 'bc_csv_import_blog_posts.generate_test_csv';
    }

    protected function getCommandDescription(): string
    {
        return 'BlogPosts テスト用CSVファイルを生成します。';
    }

    protected function getService(): CsvImportServiceInterface
    {
        return new BlogPostsCsvImportService();
    }

    protected function getFilenamePrefix(): string
    {
        return 'import_blog_posts_';
    }

    protected function buildRow(int $i, array $columnKeys): array
    {
        $categories = ['お知らせ', 'ニュース', 'コラム', 'イベント', 'プレスリリース'];
        $tagGroups = ['PHP,CakePHP', 'baserCMS,Web', '開発,設計', 'デザイン,UI', '', 'PHP,baserCMS'];
        $statuses = [1, 1, 1, 1, 0];
        $baseDate = new \DateTimeImmutable('2026-01-01 10:00:00');
        $category = $categories[($i - 1) % count($categories)];
        $tags = $tagGroups[($i - 1) % count($tagGroups)];
        $status = $statuses[($i - 1) % count($statuses)];
        $posted = $baseDate->modify('+' . ($i - 1) . ' days')->format('Y-m-d H:i:s');
        $row = [];
        foreach ($columnKeys as $key) {
            $row[$key] = match ($key) {
                'no'                 => '',
                'name'               => 'test-post-' . sprintf('%07d', $i),
                'title'              => 'テスト記事タイトル ' . $i . '：' . $category . 'のお知らせ',
                'content'            => $category . 'の概要テキスト（記事' . $i . '）',
                'detail'             => $category . 'の詳細テキスト（記事' . $i . '）。本文の詳細本文です。',
                'blog_category_name' => $category,
                'blog_tag_names'     => $tags,
                'posted'             => $posted,
                'publish_begin'      => '',
                'publish_end'        => '',
                'exclude_search'     => 0,
                'eye_catch'          => '',
                'status'             => $status,
                default              => '',
            };
        }
        return $row;
    }

    protected function getErrorPatterns(): array
    {
        return [
            'タイトルが空（必須項目エラー）' => function (array $row): array {
                $row['title'] = '';
                return $row;
            },
            '公開状態が不正値（バリデーションエラー）' => function (array $row): array {
                $row['status'] = 99;
                return $row;
            },
            'スラッグが重複（test-post-0000001と同じ）' => function (array $row): array {
                $row['name'] = 'test-post-0000001';
                return $row;
            },
            '公開日時が不正形式（型エラー）' => function (array $row): array {
                $row['posted'] = '不正な日付';
                return $row;
            },
        ];
    }
}
