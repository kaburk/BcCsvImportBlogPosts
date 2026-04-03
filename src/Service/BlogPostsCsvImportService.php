<?php
declare(strict_types=1);

namespace BcCsvImportBlogPosts\Service;

use BcCsvImportCore\Service\CsvImportService;
use BcCsvImportCore\Service\CsvImportServiceInterface;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * BlogPostsCsvImportService
 *
 * bc-blog の blog_posts テーブルへのCSVインポートサービス。
 * アップロード時に指定された blog_content_id のブログに記事を一括登録する。
 *
 * カテゴリ・タグの挙動は setting.php の以下のキーで制御できる。
 *   BcCsvImportBlogPosts.blogCategoryNotFound: 'error' | 'create'
 *   BcCsvImportBlogPosts.blogTagNotFound      : 'error' | 'create' | 'ignore'
 *
 * CSVフォーマット:
 *   スラッグ, タイトル, 本文, カテゴリ名, タグ（カンマ区切り）, 公開日時, 公開フラグ（1/0）
 */
class BlogPostsCsvImportService extends CsvImportService implements CsvImportServiceInterface
{
    /**
     * blog_content_id ごとの次回採番値キャッシュ
     *
     * @var array<int, int>
     */
    private array $noCache = [];

    /**
     * インポート対象のテーブル名
     *
     * @return string
     */
    public function getTableName(): string
    {
        return 'BcBlog.BlogPosts';
    }

    /**
     * CSVカラムマップ
     *
     * @return array
     */
    public function getColumnMap(): array
    {
        return [
            'no' => [
                'label' => 'No',
                'required' => false,
                'sample' => '',
            ],
            'name' => [
                'label' => 'スラッグ',
                'required' => false,
                'sample' => 'my-first-post',
            ],
            'title' => [
                'label' => 'タイトル',
                'required' => true,
                'sample' => 'サンプル記事タイトル',
            ],
            'content' => [
                'label' => '本文（概要）',
                'required' => false,
                'sample' => '記事の概要テキスト',
            ],
            'detail' => [
                'label' => '本文（詳細）',
                'required' => false,
                'sample' => '記事の詳細テキスト',
            ],
            'blog_category_name' => [
                'label' => 'カテゴリ名',
                'required' => false,
                'sample' => 'お知らせ',
            ],
            'blog_tag_names' => [
                'label' => 'タグ（半角カンマ区切り）',
                'required' => false,
                'sample' => 'PHP,CakePHP',
            ],
            'posted' => [
                'label' => '公開日時',
                'required' => false,
                'sample' => '2026-04-01 10:00:00',
            ],
            'publish_begin' => [
                'label' => '公開開始日時',
                'required' => false,
                'sample' => '2026-04-01 10:00:00',
            ],
            'publish_end' => [
                'label' => '公開終了日時',
                'required' => false,
                'sample' => '2026-04-30 23:59:59',
            ],
            'exclude_search' => [
                'label' => '検索除外',
                'required' => false,
                'sample' => '0',
            ],
            'eye_catch' => [
                'label' => 'アイキャッチ画像',
                'required' => false,
                'sample' => '2026/04/01/eyecatch.jpg',
            ],
            'status' => [
                'label' => '公開状態',
                'required' => false,
                'sample' => '1',
            ],
        ];
    }

    /**
     * 重複チェックに使うカラム名
     *
     * スラッグ（name）が同じ記事は重複とみなす。
     * name が空の場合は重複チェックをスキップする。
     *
     * @return string
     */
    public function getDuplicateKey(): string
    {
        return 'name';
    }

    /**
     * CSV1行からEntityを生成する
     *
     * blog_content_id は jobMeta から取得する（アップロード画面でユーザーが選択したブログ）。
     * カテゴリ・タグは名前で検索し、設定に応じて自動生成またはエラーにする。
     *
     * @param array $row
     * @return EntityInterface
     */
    public function buildEntity(array $row): EntityInterface
    {
        $blogContentId = (int)$this->getJobMeta('blog_content_id', 0);
        $blogPostsTable = TableRegistry::getTableLocator()->get($this->getTableName());

        $nullIfEmpty = fn(?string $v): ?string => ($v !== null && trim($v) !== '') ? trim($v) : null;
        if (!isset($this->noCache[$blogContentId])) {
            $this->noCache[$blogContentId] = $blogPostsTable->getMax('no', ['BlogPosts.blog_content_id' => $blogContentId]);
        }
        $no = ++$this->noCache[$blogContentId];

        $data = [
            'blog_content_id' => $blogContentId,
            'no'              => $no,
            'name'            => $nullIfEmpty($row['name'] ?? null),
            'title'           => $nullIfEmpty($row['title'] ?? null),
            'content'         => $nullIfEmpty($row['content'] ?? null),
            'detail'          => $nullIfEmpty($row['detail'] ?? null),
            'posted'          => $nullIfEmpty($row['posted'] ?? null)
                ? new DateTime($row['posted'])
                : new DateTime(),
            'status'          => isset($row['status']) && $row['status'] !== ''
                ? (bool)(int)$row['status']
                : true,
            'user_id'         => 1,
            'publish_begin'   => $nullIfEmpty($row['publish_begin'] ?? null)
                ? new DateTime($row['publish_begin'])
                : null,
            'publish_end'     => $nullIfEmpty($row['publish_end'] ?? null)
                ? new DateTime($row['publish_end'])
                : null,
            'exclude_search'  => isset($row['exclude_search']) && $row['exclude_search'] !== ''
                ? (bool)(int)$row['exclude_search']
                : false,
            'eye_catch'       => $nullIfEmpty($row['eye_catch'] ?? null),
        ];

        $entity = $blogPostsTable->newEntity($data, ['validate' => 'default']);

        // スラッグの重複は blog_content_id 単位で CsvImportService 側が判定するため、
        // BlogPostsTable のグローバル unique バリデーションはここでは外す。
        $nameErrors = $entity->getError('name');
        if ($nameErrors) {
            unset($nameErrors['nameUnique']);
            $entity->setError('name', $nameErrors, true);
        }

        // カテゴリの解決
        $categoryName = $nullIfEmpty($row['blog_category_name'] ?? null);
        if ($categoryName !== null) {
            $categoryId = $this->resolveBlogCategory($categoryName, $blogContentId, $entity);
            if ($categoryId !== null) {
                $entity->set('blog_category_id', $categoryId);
            }
        }

        // タグの解決
        $tagNamesRaw = $nullIfEmpty($row['blog_tag_names'] ?? null);
        if ($tagNamesRaw !== null) {
            $tagIds = $this->resolveBlogTags($tagNamesRaw, $blogContentId, $entity);
            if ($tagIds !== null) {
                $entity->set('blog_tags', $blogPostsTable->BlogTags->find()
                    ->where(['BlogTags.id IN' => $tagIds])
                    ->all()
                    ->toList());
            }
        }

        return $entity;
    }

    /**
     * カテゴリ名（title）から blog_category_id を解決する
     *
     * CSV の「カテゴリ名」列はユーザーが入力する表示名（title）で照合する。
     * BlogCategories.name はスラッグ（半角英数字・ハイフン・アンダースコアのみ）であり
     * 日本語タイトルをそのまま渡すとバリデーション失敗になるため、
     * 自動作成時は uniqid ベースでスラッグを生成する。
     *
     * @param string $title CSV に入力されたカテゴリの表示名
     * @param int $blogContentId
     * @param EntityInterface $entity エラーを乗せるためのエンティティ
     * @return int|null
     */
    private function resolveBlogCategory(string $title, int $blogContentId, EntityInterface $entity): ?int
    {
        $categoriesTable = TableRegistry::getTableLocator()->get('BcBlog.BlogCategories');

        // title（表示名）で検索する
        $category = $categoriesTable->find()
            ->where([
                'title'           => $title,
                'blog_content_id' => $blogContentId,
            ])
            ->first();

        if ($category) {
            return $category->id;
        }

        $mode = Configure::read('BcCsvImportBlogPosts.blogCategoryNotFound', 'error');

        if ($mode === 'create') {
            $slug = $this->titleToSlug($title);
            $nextNo = $categoriesTable->getMax('no', ['BlogCategories.blog_content_id' => $blogContentId]) + 1;
            $newCategory = $categoriesTable->newEntity([
                'name'            => $slug,
                'title'           => $title,
                'blog_content_id' => $blogContentId,
                'no'              => $nextNo,
                'status'          => true,
            ]);
            if ($categoriesTable->save($newCategory)) {
                return $newCategory->id;
            }
            // save 失敗（DB 制約・バリデーション）
            $entity->setError('blog_category_name', [
                sprintf('カテゴリ「%s」の自動作成に失敗しました。', $title),
            ]);
            return null;
        }

        // mode === 'error'
        $entity->setError('blog_category_name', [
            sprintf('カテゴリ「%s」が見つかりません。', $title),
        ]);
        return null;
    }

    /**
     * タイトル文字列をスラッグ用の半角英数字ハイフン文字列に変換する
     *
     * PHP intl 拡張の Transliterator を使用して日本語を音写（ローマ字化）する。
     * ひらがな・カタカナはヘボン式相当のローマ字に、漢字は中国語ピンインになる。
     * 変換結果が空の場合は 'cat-{uniqid}' にフォールバックする。
     *
     * @param string $title
     * @return string
     */
    private function titleToSlug(string $title): string
    {
        $slug = null;

        if (class_exists('Transliterator')) {
            $tr = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
            if ($tr !== null) {
                $transliterated = $tr->transliterate($title);
                if ($transliterated !== false) {
                    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $transliterated), '-');
                    if (strlen($slug) > 80) {
                        $slug = rtrim(substr($slug, 0, 80), '-');
                    }
                }
            }
        }

        if (empty($slug)) {
            return 'cat-' . uniqid();
        }

        return 'cat-' . $slug;
    }

    /**
     * タグ名文字列（カンマ区切り）から blog_tag_id の配列を解決する
     *
     * @param string $tagNamesRaw
     * @param int $blogContentId
     * @param EntityInterface $entity
     * @return int[]|null null の場合はタグなしで登録
     */
    private function resolveBlogTags(string $tagNamesRaw, int $blogContentId, EntityInterface $entity): ?array
    {
        $tagsTable = TableRegistry::getTableLocator()->get('BcBlog.BlogTags');
        $mode = Configure::read('BcCsvImportBlogPosts.blogTagNotFound', 'ignore');
        $tagNames = array_filter(array_map('trim', explode(',', $tagNamesRaw)));
        $tagIds = [];

        foreach ($tagNames as $tagName) {
            $tag = $tagsTable->find()->where(['name' => $tagName])->first();
            if ($tag) {
                $tagIds[] = $tag->id;
                continue;
            }

            if ($mode === 'create') {
                $newTag = $tagsTable->newEntity(['name' => $tagName]);
                if ($tagsTable->save($newTag)) {
                    $tagIds[] = $newTag->id;
                }
                continue;
            }

            if ($mode === 'error') {
                $entity->setError('blog_tag_names', [
                    sprintf('タグ「%s」が見つかりません。', $tagName),
                ]);
                return null;
            }

            // ignore: そのタグは付けずに続行
        }

        return $tagIds ?: null;
    }

    /**
     * blog_content_id 単位で既存スラッグを検索する
     *
     * @param string $duplicateKey
     * @param array $candidateData
     * @return array
     */
    protected function buildDuplicateSearchConditions(string $duplicateKey, array $candidateData): array
    {
        $conditions = parent::buildDuplicateSearchConditions($duplicateKey, $candidateData);
        $blogContentId = (int)$this->getJobMeta('blog_content_id', 0);
        if (!$conditions || $blogContentId <= 0) {
            return $conditions;
        }

        $conditions['blog_content_id'] = $blogContentId;
        return $conditions;
    }

    /**
     * blog_content_id とスラッグの組み合わせで重複識別子を作る
     *
     * @param array $data
     * @param string $duplicateKey
     * @return string|null
     */
    protected function buildDuplicateIdentity(array $data, string $duplicateKey): ?string
    {
        $slug = parent::buildDuplicateIdentity($data, $duplicateKey);
        if ($slug === null) {
            return null;
        }

        return (string)$this->getJobMeta('blog_content_id', 0) . ':' . $slug;
    }

    /**
     * 既存記事から blog_content_id とスラッグの組み合わせ識別子を作る
     *
     * @param EntityInterface $entity
     * @param string $duplicateKey
     * @return string|null
     */
    protected function buildDuplicateIdentityFromEntity(EntityInterface $entity, string $duplicateKey): ?string
    {
        $slug = parent::buildDuplicateIdentityFromEntity($entity, $duplicateKey);
        if ($slug === null) {
            return null;
        }

        return (string)$entity->get('blog_content_id') . ':' . $slug;
    }

    /**
     * 指定ブログの記事一覧CSVを生成する
     *
     * @param int $blogContentId
     * @return string
     */
    public function buildDownloadCsv(int $blogContentId): string
    {
        $blogPostsTable = TableRegistry::getTableLocator()->get($this->getTableName());
        $blogPosts = $blogPostsTable->find()
            ->contain(['BlogCategories', 'BlogTags'])
            ->where(['BlogPosts.blog_content_id' => $blogContentId])
            ->orderBy(['BlogPosts.no' => 'ASC', 'BlogPosts.id' => 'ASC'])
            ->all();

        $columnMap = $this->getColumnMap();
        $headers = array_map(fn($value) => $value['label'], $columnMap);
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);

        foreach ($blogPosts as $blogPost) {
            $tagNames = [];
            foreach ((array)$blogPost->get('blog_tags') as $blogTag) {
                $tagNames[] = $blogTag->name;
            }
            $blogCategory = $blogPost->get('blog_category');

            fputcsv($output, [
                (string)($blogPost->get('no') ?? ''),
                (string)($blogPost->get('name') ?? ''),
                (string)($blogPost->get('title') ?? ''),
                (string)($blogPost->get('content') ?? ''),
                (string)($blogPost->get('detail') ?? ''),
                (string)($blogCategory?->title ?? ''),
                implode(',', $tagNames),
                $this->formatCsvDate($blogPost->get('posted')),
                $this->formatCsvDate($blogPost->get('publish_begin')),
                $this->formatCsvDate($blogPost->get('publish_end')),
                $blogPost->get('exclude_search') ? '1' : '0',
                (string)($blogPost->get('eye_catch') ?? ''),
                $blogPost->get('status') ? '1' : '0',
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * ダウンロード用ファイル名を生成する
     *
     * @param int $blogContentId
     * @return string
     */
    public function buildDownloadFilename(int $blogContentId): string
    {
        return sprintf('blog_posts-%d.csv', $blogContentId);
    }

    /**
     * CSV向けに日時を文字列化する
     *
     * @param mixed $value
     * @return string
     */
    private function formatCsvDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof DateTime) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string)$value;
    }

}
