<?php
declare(strict_types=1);

namespace BcCsvImportBlogPosts\Controller\Admin;

use BcCsvImportBlogPosts\Service\Admin\BlogPostsCsvImportAdminService;
use BcCsvImportBlogPosts\Service\BlogPostsCsvImportService;
use BcCsvImportCore\Controller\Admin\CsvImportsController;
use BcCsvImportCore\Service\Admin\CsvImportAdminServiceInterface;
use BcCsvImportCore\Service\CsvImportServiceInterface;
use Cake\Http\Response;
use Cake\Log\Log;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * BlogPostsCsvImportsController
 *
 * BcCsvImportCore の CsvImportsController を継承し、
 * アップロード時に blog_content_id を jobMeta として渡す。
 * テンプレートは BcCsvImportCore のものをベースに、ブログ選択UIを追加したものを使用する。
 */
class BlogPostsCsvImportsController extends CsvImportsController
{
    /**
     * インポートサービスを生成する
     *
     * @return CsvImportServiceInterface
     */
    protected function createImportService(): CsvImportServiceInterface
    {
        return new BlogPostsCsvImportService();
    }

    /**
     * 管理サービスを生成する
     *
     * @return CsvImportAdminServiceInterface
     */
    protected function createAdminService(): CsvImportAdminServiceInterface
    {
        return new BlogPostsCsvImportAdminService();
    }

    /**
     * [AJAX] CSVアップロード（ブログ選択対応版）
     *
     * リクエストから blog_content_id を受け取り、
     * createJob() の options['meta'] に格納する。
     * BlogPostsCsvImportService::buildEntity() からは
     * $this->getJobMeta('blog_content_id') で参照できる。
     *
     * @return Response
     */
    public function upload(): Response
    {
        $this->request->allowMethod('post');

        $uploadedFile = $this->resolveUploadedFile('csv_file');
        $encoding = $this->request->getData('encoding', 'auto');
        $mode = $this->request->getData('mode', 'strict');
        $importStrategy = $this->request->getData('import_strategy', 'append');
        $duplicateMode = $this->request->getData('duplicate_mode', 'skip');
        $blogContentId = (int)$this->request->getData('blog_content_id', 0);

        if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            Log::warning(sprintf(
                '[BcCsvImportBlogPosts] upload_file_missing type=%s error=%s',
                is_object($this->request->getData('csv_file'))
                    ? get_class($this->request->getData('csv_file'))
                    : gettype($this->request->getData('csv_file')),
                $uploadedFile?->getError() ?? 'null'
            ), 'csv_import');
            return $this->_jsonResponse(['message' => __d('baser_core', 'CSVファイルをアップロードしてください。')], 400);
        }

        if ($blogContentId <= 0) {
            return $this->_jsonResponse(['message' => __d('baser_core', 'インポート先のブログを選択してください。')], 400);
        }

        try {
            $tmpDir = TMP . 'csv_imports' . DS;
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0777, true);
            }
            $tmpPath = $tmpDir . uniqid('csv_', true) . '.csv';
            $uploadedFile->moveTo($tmpPath);

            $resolvedEncoding = $encoding === 'auto'
                ? $this->importService->detectEncoding($tmpPath)
                : $encoding;
            $this->importService->convertCsvEncoding($tmpPath, $resolvedEncoding);

            $job = $this->importService->createJob($tmpPath, [
                'mode' => $mode,
                'import_strategy' => $importStrategy,
                'duplicate_mode' => $duplicateMode,
                'meta' => [
                    'blog_content_id' => $blogContentId,
                ],
            ]);

            return $this->_jsonResponse([
                'job' => [
                    'token' => $job->job_token,
                    'total' => $job->total,
                    'mode' => $job->mode,
                    'import_strategy' => $job->import_strategy,
                    'target_cleared' => (bool)$job->target_cleared,
                    'duplicate_mode' => $job->duplicate_mode,
                    'encoding' => $resolvedEncoding,
                ],
            ]);
        } catch (Throwable $e) {
            if (isset($tmpPath) && file_exists($tmpPath)) {
                unlink($tmpPath);
            }
            $isValidationError = $e instanceof \RuntimeException
                && str_contains($e->getMessage(), 'CSVのヘッダ');
            if ($isValidationError) {
                Log::warning('[BcCsvImportBlogPosts] header_validation_error ' . $e->getMessage(), 'csv_import');
                return $this->_jsonResponse(['message' => $e->getMessage()], 400);
            }
            Log::error('[BcCsvImportBlogPosts] upload_error ' . $e->getMessage(), 'csv_import');
            return $this->_jsonResponse(['message' => __d('baser_core', 'アップロードに失敗しました。') . $e->getMessage()], 500);
        }
    }

    /**
     * ブログ記事CSVダウンロード
     *
     * @return Response
     */
    public function download_posts(): Response
    {
        $this->request->allowMethod('get');

        $blogContentId = (int)$this->request->getQuery('blog_content_id', 0);
        if ($blogContentId <= 0) {
            throw new \InvalidArgumentException(__d('baser_core', 'ダウンロード対象のブログを選択してください。'));
        }

        /** @var BlogPostsCsvImportService $service */
        $service = $this->importService;
        $filename = $service->buildDownloadFilename($blogContentId);

        return $this->response
            ->withType('text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withStringBody($service->buildDownloadCsv($blogContentId));
    }

    /**
     * アップロードファイルを取得する
     *
     * Cake のリクエスト実装差異により getUploadedFile() で取得できない場合があるため、
     * getData() もフォールバックとして確認する。
     *
     * @param string $field
     * @return UploadedFileInterface|null
     */
    private function resolveUploadedFile(string $field): ?UploadedFileInterface
    {
        $uploadedFile = $this->request->getUploadedFile($field);
        if ($uploadedFile instanceof UploadedFileInterface) {
            return $uploadedFile;
        }

        $data = $this->request->getData($field);
        if ($data instanceof UploadedFileInterface) {
            return $data;
        }

        return null;
    }

    /**
     * JSONレスポンスを返す（親クラスが private のため再定義）
     *
     * @param array $data
     * @param int $status
     * @return Response
     */
    private function _jsonResponse(array $data, int $status = 200): Response
    {
        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

}
