<?php
declare(strict_types=1);

namespace BcCsvImportBlogPosts\Test\TestCase\Service;

use BaserCore\TestSuite\BcTestCase;
use BcCsvImportBlogPosts\Service\BlogPostsCsvImportService;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use ReflectionMethod;

/**
 * BlogPostsCsvImportServiceTest
 *
 * BlogPostsCsvImportService のカラムマップ・重複識別子・buildEntity・
 * カテゴリ/タグ解決ロジックを検証する。
 *
 * DB を使うテストは Docker 環境で bc-blog マイグレーション済みであることを前提とする。
 */
class BlogPostsCsvImportServiceTest extends BcTestCase
{
    /**
     * テスト用サブクラス（jobMeta を直接セットして blog_content_id を固定する）
     */
    private BlogPostsCsvImportService $service;

    public function setUp(): void
    {
        parent::setUp();

        // protected な $jobMeta を直接セットできるサブクラスを無名クラスで生成する
        $this->service = new class extends BlogPostsCsvImportService {
            public function setJobMetaDirect(array $meta): void
            {
                $this->jobMeta = $meta;
            }
        };
    }

    // ─────────────────────────────────────────────────────────────
    // getTableName
    // ─────────────────────────────────────────────────────────────

    public function testGetTableNameReturnsBlogPosts(): void
    {
        $this->assertSame('BcBlog.BlogPosts', $this->service->getTableName());
    }

    // ─────────────────────────────────────────────────────────────
    // getColumnMap
    // ─────────────────────────────────────────────────────────────

    public function testGetColumnMapReturnsExpectedKeys(): void
    {
        $expectedKeys = [
            'no', 'name', 'title', 'content', 'detail',
            'blog_category_name', 'blog_tag_names',
            'posted', 'publish_begin', 'publish_end',
            'exclude_search', 'eye_catch', 'status',
        ];
        $this->assertSame($expectedKeys, array_keys($this->service->getColumnMap()));
    }

    public function testGetColumnMapHasLabelForEachKey(): void
    {
        foreach ($this->service->getColumnMap() as $key => $definition) {
            $this->assertArrayHasKey('label', $definition, "カラム '{$key}' に label キーが必要");
            $this->assertNotEmpty($definition['label'], "カラム '{$key}' の label が空");
        }
    }

    public function testGetColumnMapTitleIsRequired(): void
    {
        $map = $this->service->getColumnMap();
        $this->assertTrue($map['title']['required'] ?? false, "'title' は required=true であるべき");
    }

    // ─────────────────────────────────────────────────────────────
    // getDuplicateKey
    // ─────────────────────────────────────────────────────────────

    public function testGetDuplicateKeyReturnsName(): void
    {
        $this->assertSame('name', $this->service->getDuplicateKey());
    }

    // ─────────────────────────────────────────────────────────────
    // buildDuplicateIdentity（protected、ReflectionMethod で呼ぶ）
    // ─────────────────────────────────────────────────────────────

    public function testBuildDuplicateIdentityPrependsBlogContentId(): void
    {
        /** @var \BcCsvImportBlogPosts\Service\BlogPostsCsvImportService $service */
        $service = $this->service;
        $service->setJobMetaDirect(['blog_content_id' => 3]);

        $method = new ReflectionMethod(BlogPostsCsvImportService::class, 'buildDuplicateIdentity');

        $result = $method->invoke($service, ['name' => 'my-post'], 'name');

        $this->assertSame('3:my-post', $result);
    }

    public function testBuildDuplicateIdentityReturnsNullWhenSlugEmpty(): void
    {
        /** @var \BcCsvImportBlogPosts\Service\BlogPostsCsvImportService $service */
        $service = $this->service;
        $service->setJobMetaDirect(['blog_content_id' => 1]);

        $method = new ReflectionMethod(BlogPostsCsvImportService::class, 'buildDuplicateIdentity');

        $result = $method->invoke($service, ['name' => ''], 'name');

        $this->assertNull($result);
    }

    public function testBuildDuplicateIdentityFromEntityPrependsBlogContentId(): void
    {
        $blogPostsTable = TableRegistry::getTableLocator()->get('BcBlog.BlogPosts');
        $entity = $blogPostsTable->newEmptyEntity();
        $entity->set('name', 'test-slug');
        $entity->set('blog_content_id', 5);

        $method = new ReflectionMethod(BlogPostsCsvImportService::class, 'buildDuplicateIdentityFromEntity');

        $result = $method->invoke($this->service, $entity, 'name');

        $this->assertSame('5:test-slug', $result);
    }

    // ─────────────────────────────────────────────────────────────
    // buildEntity（DB 使用 / blog_content_id=0 で blog_posts テーブルを参照）
    // ─────────────────────────────────────────────────────────────

    public function testBuildEntitySetsTitleAndDefaults(): void
    {
        $entity = $this->service->buildEntity([
            'no'                 => '',
            'name'               => 'sample-slug',
            'title'              => 'テスト記事',
            'content'            => '概要テキスト',
            'detail'             => '',
            'blog_category_name' => '',
            'blog_tag_names'     => '',
            'posted'             => '',
            'publish_begin'      => '',
            'publish_end'        => '',
            'exclude_search'     => '',
            'eye_catch'          => '',
            'status'             => '1',
        ]);

        $this->assertSame('テスト記事', $entity->get('title'));
        $this->assertSame('sample-slug', $entity->get('name'));
        $this->assertTrue($entity->get('status'));
        $this->assertFalse($entity->get('exclude_search'));
        $this->assertNotNull($entity->get('no'), 'no は自動採番されること');
    }

    public function testBuildEntityHasErrorWhenTitleIsEmpty(): void
    {
        $entity = $this->service->buildEntity([
            'no'                 => '',
            'name'               => '',
            'title'              => '',
            'content'            => '',
            'detail'             => '',
            'blog_category_name' => '',
            'blog_tag_names'     => '',
            'posted'             => '',
            'publish_begin'      => '',
            'publish_end'        => '',
            'exclude_search'     => '',
            'eye_catch'          => '',
            'status'             => '',
        ]);

        $this->assertTrue($entity->hasErrors(), 'タイトル空のときバリデーションエラーになること');
        $this->assertArrayHasKey('title', $entity->getErrors());
    }

    // ─────────────────────────────────────────────────────────────
    // カテゴリ解決（設定モード: error）
    // ─────────────────────────────────────────────────────────────

    public function testBuildEntityCategoryErrorModeAddsErrorWhenCategoryNotFound(): void
    {
        Configure::write('BcCsvImportBlogPosts.blogCategoryNotFound', 'error');

        $entity = $this->service->buildEntity([
            'no'                 => '',
            'name'               => '',
            'title'              => 'カテゴリエラーテスト',
            'content'            => '',
            'detail'             => '',
            'blog_category_name' => '存在しないカテゴリ_テスト専用',
            'blog_tag_names'     => '',
            'posted'             => '',
            'publish_begin'      => '',
            'publish_end'        => '',
            'exclude_search'     => '',
            'eye_catch'          => '',
            'status'             => '1',
        ]);

        $this->assertTrue($entity->hasErrors(), 'カテゴリが見つからないときエラーになること');
        $this->assertArrayHasKey('blog_category_name', $entity->getErrors());
    }

    // ─────────────────────────────────────────────────────────────
    // タグ解決（設定モード: error）
    // ─────────────────────────────────────────────────────────────

    public function testBuildEntityTagErrorModeAddsErrorWhenTagNotFound(): void
    {
        Configure::write('BcCsvImportBlogPosts.blogTagNotFound', 'error');

        $entity = $this->service->buildEntity([
            'no'                 => '',
            'name'               => '',
            'title'              => 'タグエラーテスト',
            'content'            => '',
            'detail'             => '',
            'blog_category_name' => '',
            'blog_tag_names'     => '存在しないタグ_テスト専用',
            'posted'             => '',
            'publish_begin'      => '',
            'publish_end'        => '',
            'exclude_search'     => '',
            'eye_catch'          => '',
            'status'             => '1',
        ]);

        $this->assertTrue($entity->hasErrors(), 'タグが見つからないときエラーになること');
        $this->assertArrayHasKey('blog_tag_names', $entity->getErrors());
    }

    public function testBuildEntityTagIgnoreModeSkipsUnknownTag(): void
    {
        Configure::write('BcCsvImportBlogPosts.blogTagNotFound', 'ignore');

        $entity = $this->service->buildEntity([
            'no'                 => '',
            'name'               => '',
            'title'              => 'タグ無視テスト',
            'content'            => '',
            'detail'             => '',
            'blog_category_name' => '',
            'blog_tag_names'     => '存在しないタグ_テスト専用',
            'posted'             => '',
            'publish_begin'      => '',
            'publish_end'        => '',
            'exclude_search'     => '',
            'eye_catch'          => '',
            'status'             => '1',
        ]);

        $errors = $entity->getErrors();
        $this->assertArrayNotHasKey('blog_tag_names', $errors, 'ignore モードではタグエラーにならないこと');
    }
}
