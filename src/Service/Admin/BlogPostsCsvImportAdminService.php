<?php
declare(strict_types=1);

namespace BcCsvImportBlogPosts\Service\Admin;

use BcCsvImportCore\Service\Admin\CsvImportAdminService;
use Cake\ORM\TableRegistry;

/**
 * BlogPostsCsvImportAdminService
 *
 * アップロード画面用の View 変数にブログ一覧を追加する。
 * ブログ選択プルダウンに必要な `blogOptions` を提供する。
 */
class BlogPostsCsvImportAdminService extends CsvImportAdminService
{

    /**
     * ブログ一覧など追加の View 変数を返す
     *
     * index アクションから呼ばれ、テンプレートに `$blogOptions` が渡る。
     * blogOptions は ['サイト名 / ブログ名' => blog_content_id] の連想配列。
     *
     * @return array
     */
    protected function getExtraViewVars(): array
    {
        $blogContents = TableRegistry::getTableLocator()
            ->get('BcBlog.BlogContents')
            ->find()
            ->contain(['Contents' => ['Sites']])
            ->orderBy(['Sites.name' => 'ASC', 'Contents.title' => 'ASC'])
            ->all();

        $blogOptions = [];
        foreach ($blogContents as $blogContent) {
            $siteName = $blogContent->content->site->display_name
                ?? $blogContent->content->site->name
                ?? '';
            $blogTitle = $blogContent->content->title ?? '';
            $label = $siteName ? "{$siteName} / {$blogTitle}" : $blogTitle;
            $blogOptions[$blogContent->id] = $label;
        }

        return [
            'blogOptions' => $blogOptions,
        ];
    }

}
