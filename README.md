# BcCsvImportBlogPosts

`BcCsvImportBlogPosts` は、`BcCsvImportCore` を使って `bc-blog` の記事を CSV から一括登録・ダウンロードするプラグインです。
インポート先ブログを管理画面で選択できます。

## 用途

- ブログ記事の一括登録
- 選択したブログの記事CSVダウンロード
- `jobMeta` を使った追加パラメータ受け渡しの実装例
- カテゴリ・タグ解決を含む実案件向け実装の参考

## 前提

- `BcCsvImportCore` を有効化済みであること
- `BcBlog` が利用可能であること
- インポート先のブログコンテンツが作成済みであること

## 管理画面

- メニュー名: `ブログ記事CSVインポート`
- URL: `/baser/admin/bc-csv-import-blog-posts/blog_posts_csv_imports/index`

画面構成は `BcCsvImportCore` の共通UIをベースにしつつ、次の独自UIを追加しています。

- インポート先ブログ選択プルダウン
- 「選択中ブログの記事CSVダウンロード」ボタン

## 対象テーブル

- テーブル: `BcBlog.BlogPosts`
- 重複キー: `name`（スラッグ）
- 重複判定: `blog_content_id + name` の組み合わせで判定
- ジョブ一覧: `target_table` により本プラグインのジョブだけを表示

## CSVフォーマット

テンプレートCSVおよび記事CSVダウンロードのヘッダは次の通りです。

```csv
No,スラッグ,タイトル,本文（概要）,本文（詳細）,カテゴリ名,タグ（半角カンマ区切り）,公開日時,公開開始日時,公開終了日時,検索除外,アイキャッチ画像,公開状態
```

### 列の補足

- `No`: CSV値は取り込まず、インポート時に選択ブログ内の最大 `no` から自動採番する
- `カテゴリ名`: `BlogCategories.title` で照合する
- `タグ（半角カンマ区切り）`: カンマ区切りのタグ名で指定する
- `検索除外`: `0` または `1`
- `公開状態`: `0` または `1`

### 記事CSVダウンロード

- 画面上でブログを選択した状態で実行する
- 出力形式はインポートしやすいよう、上記ヘッダ順に揃えている
- カテゴリは表示名、タグはカンマ区切りで出力する

## 固定設定（例）

- インポート方式: `append` 固定
- 重複処理: `skip` 固定

用途をブログ記事インポートに絞るため、管理画面ではこれらの選択UIを非表示にしています。

## カテゴリ・タグの挙動

CSV の「カテゴリ名」「タグ」列の値が DB に存在しない場合の動作を、`config/setting.php` の設定キーで制御できます。

### blogCategoryNotFound — カテゴリが存在しない場合

```php
'BcCsvImportCore' => [
    'blogCategoryNotFound' => 'error', // 初期値
]
```

| 値 | 挙動 |
|---|---|
| `error`（初期値） | その行をエラーとしてスキップし、エラーCSVにカテゴリ名を記録する。インポート自体は続行する。 |
| `create` | 同名（`title`）のカテゴリを自動作成してインポートを続行する。スラッグはタイトルから英数字ハイフン形式へ変換し、変換できない場合は `uniqid` でフォールバックする。 |

> **検索について:** CSV の「カテゴリ名」列は `title`（表示名）で照合します。  
> `BlogCategories.name` はスラッグ（半角英数字・ハイフン・アンダースコアのみ）のため、  
> 日本語カテゴリ名を入力した場合でも正しく検索・作成できます。

> **注意:** `create` を指定した場合、スペルミスのカテゴリ名が大量に作成されるリスクがあります。
> 本番運用では `error` のまま使い、事前にカテゴリを整備してから実行することを推奨します。

### blogTagNotFound — タグが存在しない場合

```php
'BcCsvImportCore' => [
    'blogTagNotFound' => 'ignore', // 初期値
]
```

| 値 | 挙動 |
|---|---|
| `ignore`（初期値） | 存在しないタグは無視し、存在するタグだけを付けてインポートを続行する。 |
| `error` | 存在しないタグが1件でもあれば、その行をエラーとしてスキップする。 |
| `create` | 存在しないタグを自動作成してインポートを続行する。 |

> **補足:** タグは `blog_content_id` に依存しない（サイト全体で共有）のに対し、
> カテゴリは `blog_content_id` ごとに管理されます。
> `create` でカテゴリを自動作成する場合、対象ブログのコンテンツIDに紐づいて作成され、
> `no` はブログ単位の次番号、`status` は `1` 固定で保存されます。

## スラッグ重複判定

- `BlogPostsTable` のグローバルな `nameUnique` バリデーションは、そのままだと他ブログの記事とも衝突する
- 本プラグインでは `blog_content_id + name` の組み合わせで既存記事を検索し、選択ブログ内でのみ重複判定する
- そのため、別ブログで同じスラッグを使っていてもインポート可能

## 実装の見どころ

- サービス実装: `src/Service/BlogPostsCsvImportService.php`
- 管理サービス: `src/Service/Admin/BlogPostsCsvImportAdminService.php`
- 専用コントローラー: `src/Controller/Admin/BlogPostsCsvImportsController.php`
- 画面テンプレート: `templates/Admin/BlogPostsCsvImports/index.php`

追加UIがあるため、このプラグインは `BcCsvImportCore` の共通テンプレートをそのままは使わず、専用テンプレートを持っています。

### 挙動のまとめ

```
           カテゴリ未存在    タグ未存在
初期値     error            ignore
自動作成   create           create
完全エラー error            error
```

## テストデータ生成

大量件数で挙動確認したい場合は、CakePHP コンソールコマンドでテスト用 CSV を生成できます。

```bash
bin/cake BcCsvImportBlogPosts.generate_test_csv
```

CSVヘッダは `BlogPostsCsvImportService::getColumnMap()` から自動取得するため、
カラム定義を変更しても常にインポート仕様と一致します。

生成ファイル名は `import_blog_posts_*.csv` です。
例: `--sizes=10k --errors=5` の場合は `import_blog_posts_10k_err5pct.csv` が生成されます。

主なオプション:

- `--output=/path/to/dir` 出力先ディレクトリを変更（デフォルト: `tmp/csv/`）
- `--sizes=10k,100k` 生成件数をカンマ区切りで指定（デフォルト: `10k` / `k`・`m` サフィックス対応）
- `--errors=5` エラー行を約 5% 含める（デフォルト: `0`）

エラー行は一定間隔で差し込まれ、タイトル未入力、不正公開状態、重複スラッグ、不正日付などのパターンを確認できます。

ヘルプを表示するには:

```bash
bin/cake BcCsvImportBlogPosts.generate_test_csv --help
```

## ライセンス

MIT License. 詳細は `LICENSE.txt` を参照してください。
