<?php
// 実行方法: vendor/bin/phpunit test/CsvFileIteratorTest.php
require_once dirname(__DIR__) . '/vendor/autoload.php';

use waterada\CsvFileIterator\CsvFileIterator;
use waterada\CsvFileIterator\Record;

/**
 * Class CsvFileIteratorTest
 */
class CsvFileIteratorTest extends PHPUnit_Framework_TestCase
{

    public function provider_base_files()
    {
        $csv = "列1,列2,列3\nあ,い,う\n";
        $tsv = "列1\t列2\t列3\nあ\tい\tう\n";
        $csv_quoted = "\"列1\",\"列2\",\"列3\"\n\"あ\",\"い\",\"う\"\n";
        $tsv_quoted = "\"列1\"\t\"列2\"\t\"列3\"\n\"あ\"\t\"い\"\t\"う\"\n";
        $bom8 = "\xef\xbb\xbf";
        $bom16le = "\xff\xfe";
        return [
            ['UTF-8 + BOMなし + CSV', $csv],
            ['UTF-8 + BOMなし + TSV', $tsv],
            ['UTF-8 + BOMあり + CSV', $bom8 . $csv],
            ['UTF-8 + BOMあり + TSV', $bom8 . $tsv],
            ['SJIS + CSV', mb_convert_encoding($csv, 'SJIS', 'UTF-8')],
            ['SJIS + TSV', mb_convert_encoding($tsv, 'SJIS', 'UTF-8')],
            ['UTF-16LE + CSV', $bom16le . mb_convert_encoding($csv, 'UTF-16LE', 'UTF-8')],
            ['UTF-16LE + TSV', $bom16le . mb_convert_encoding($tsv, 'UTF-16LE', 'UTF-8')],

            ['引用符有り + UTF-8 + BOMなし + CSV', $csv_quoted],
            ['引用符有り + UTF-8 + BOMなし + TSV', $tsv_quoted],
            ['引用符有り + UTF-8 + BOMあり + CSV', $bom8 . $csv_quoted],
            ['引用符有り + UTF-8 + BOMあり + TSV', $bom8 . $tsv_quoted],
            ['引用符有り + SJIS + CSV', mb_convert_encoding($csv_quoted, 'SJIS', 'UTF-8')],
            ['引用符有り + SJIS + TSV', mb_convert_encoding($tsv_quoted, 'SJIS', 'UTF-8')],
            ['引用符有り + UTF-16LE + CSV', $bom16le . mb_convert_encoding($csv_quoted, 'UTF-16LE', 'UTF-8')],
            ['引用符有り + UTF-16LE + TSV', $bom16le . mb_convert_encoding($tsv_quoted, 'UTF-16LE', 'UTF-8')],
        ];
    }

    /**
     * @dataProvider provider_base_files
     * @param $title
     * @param $data
     */
    public function test_base_CSVファイルを開ける($title, $data)
    {
        $csv = new CsvFileIterator(FileFabricate::fromString($data)->getPath());
        $this->assertEquals(['列1', '列2', '列3'], $csv->getColumnMapper()->getColumns(), "列名 " . $title);
        $i = 0;
        foreach ($csv->iterate() as $record) {
            $i++;
            $this->assertEquals('あ', $record->get('列1'), $i . "行目 " . $title);
            $this->assertEquals('い', $record->get('列2'), $i . "行目 " . $title);
            $this->assertEquals('う', $record->get('列3'), $i . "行目 " . $title);
            $this->assertEquals(['あ', 'い', 'う'], $record->toArray(), $i . "行目(全列) " . $title);
        }
        $this->assertEquals(1, $i); //１行だけのはず
    }

    /**
     * @param CsvFileIterator $csv
     * @return Record[]
     */
    private function __iterateRecords($csv)
    {
        $actual = [];
        foreach ($csv->iterate() as $record) {
            $actual[] = $record;
        }
        return $actual;
    }

    public function test_base_複数行取得できる()
    {
        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray([
            ['Q1', 'Q2'],
            ['11', '12'],
            ['21', '22'],
        ])->toCsv()->getPath());
        $actual = $this->__iterateRecords($csv);

        $this->assertEquals('11', $actual[0]->get('Q1'));
        $this->assertEquals('12', $actual[0]->get('Q2'));

        $this->assertEquals('21', $actual[1]->get('Q1'));
        $this->assertEquals('22', $actual[1]->get('Q2'));
    }

    public function provider_condition_exclude()
    {
        return [
            ["最初から除外対象", ['1', '2'], ['3', '4', '5']],
            ["最後から除外対象", ['4', '5'], ['1', '2', '3']],
            ["真ん中が除外対象", ['2', '3', '4'], ['1', '5']],
        ];
    }

    /**
     * @dataProvider provider_condition_exclude
     * @param $title
     * @param $excludeIds
     * @param $expected
     */
    public function test_condition_除外できる($title, $excludeIds, $expected)
    {
        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray([
            ['ID'],
            ['1'],
            ['2'],
            ['3'],
            ['4'],
            ['5'],
        ])->toCsv()->getPath());
        $csv->getColumnMapper()->setConditions(['ID' => ['NOT_IN', $excludeIds]]);

        $actual = [];
        foreach ($csv->iterate() as $record) {
            $actual[] = $record->get('ID');
        }
        $this->assertEquals($expected, $actual, $title);
    }

    public function test_condition_setConditions直後にnext呼ばずにcurrentしても大丈夫()
    {
        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray([
            ['Q1'],
            ['11'],
            ['21'],
        ])->toCsv()->getPath());
        $csv->getColumnMapper()->setConditions(['Q1' => ['NOT_IN', ['11']]]);
        $actual = $this->__iterateRecords($csv);
        $this->assertEquals('21', $actual[0]->get('Q1'));
    }

    public function test_condition_除外する行が10000行連続していてもスキップできる()
    {
        $data = [];
        $data[] = ['Q1'];
        $data[] = ['100001'];
        $excludeIds = [];
        for ($i = 0; $i < 10000; $i++) {
            $data[] = [$i];
            $excludeIds[] = $i;
        }
        $data[] = ['100002'];
        //生成したデータは目的に適っているか念の為にチェック
        $this->assertCount(10000 + 3, $data);
        $this->assertEquals(['Q1'], $data[0]);
        $this->assertEquals(['100001'], $data[1]);
        $this->assertEquals(['0'], $data[2]);
        $this->assertEquals(['9999'], $data[10001]);
        $this->assertEquals(['100002'], $data[10002]);

        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray($data)->toCsv()->getPath());
        $csv->getColumnMapper()->setConditions(['Q1' => ['NOT_IN', $excludeIds]]);

        $Q1List = [];
        foreach ($csv->iterate() as $record) {
            $Q1List[] = $record->get('Q1');
        }
        $this->assertEquals(['100001', '100002'], $Q1List);
    }

    public function test_base_ラベルだけのファイルなら空が返る()
    {
        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray([
            ['Q1', 'Q2', 'Q3'],
        ])->toCsv()->getPath());
        $Q1List = [];
        foreach ($csv->iterate() as $record) {
            $Q1List[] = $record->get('Q1');
        }
        $this->assertEquals([], $Q1List);
    }

    public function test_condition_除外結果が0件なら空が返る()
    {
        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray([
            ['Q1', 'Ex1'],
            ['1', '1'],
            ['2', '1'],
            ['3', '1'],
        ])->toCsv()->getPath());
        $csv->getColumnMapper()->setConditions(['Ex1' => ['NOT_IN', ['1']]]);

        $Q1List = [];
        foreach ($csv->iterate() as $record) {
            $Q1List[] = $record->get('Q1');
        }
        $this->assertEquals([], $Q1List);
    }

    public function test_base_取得列が指定されていないなら元の順序で取得できる()
    {
        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray([
            ['Q5', 'Q4', 'Q3', 'Q2', 'Q1'],
            ['11', '12', '13', '14', '15'],
            ['21', '22', '23', '24', '25'],
        ])->toCsv()->getPath());
        $actual = $this->__iterateRecords($csv);

        $this->assertEquals(['Q5', 'Q4', 'Q3', 'Q2', 'Q1'], $csv->getColumnMapper()->getColumns());
        $this->assertEquals(['11', '12', '13', '14', '15'], $actual[0]->toArray());
        $this->assertEquals(['21', '22', '23', '24', '25'], $actual[1]->toArray());
    }

    public function test_base_取得列が指定されていたら取得列で指定した列のみが指定順に取得できる()
    {
        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray([
            ['Q1', 'Q2', 'Q3', 'Q4', 'Q5'],
            ['11', '12', '13', '14', '15'],
            ['21', '22', '23', '24', '25'],
        ])->toCsv()->getPath());
        $csv->getColumnMapper()->setColumns(['Q5', 'Q3', 'Q1']);
        $actual = $this->__iterateRecords($csv);

        $this->assertEquals(['Q5', 'Q3', 'Q1'], $csv->getColumnMapper()->getColumns());
        $this->assertEquals(['15', '13', '11'], $actual[0]->toArray());
        $this->assertEquals(['25', '23', '21'], $actual[1]->toArray());
    }

    public function test_base_取得列として在しないカラムを含められる()
    {
        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray([
            ['Q2', 'Q4'],
            ['12', '14'],
            ['22', '24'],
        ])->toCsv()->getPath());
        $csv->getColumnMapper()->setColumns(['Q5', 'Q4', 'Q3', 'Q2', 'Q1']);
        $actual = $this->__iterateRecords($csv);

        $this->assertEquals(['Q5', 'Q4', 'Q3', 'Q2', 'Q1'], $csv->getColumnMapper()->getColumns());
        $this->assertEquals(['', '14', '', '12', ''], $actual[0]->toArray());
        $this->assertEquals(['', '24', '', '22', ''], $actual[1]->toArray());
    }

    public function provider_condition_status()
    {
        return [
            [['quotafull'], ["1", "4"]],
            [['complete'], ["2", "5"]],
            [['screened'], ["3", "6"]],
            [['quotafull', 'complete'], ["1", "2", "4", "5"]],
            [['complete', 'screened'], ["2", "3", "5", "6"]],
            [['screened', 'quotafull'], ["1", "3", "4", "6"]],
            [['complete', 'screened', 'quotafull'], ["1", "2", "3", "4", "5", "6"]],
        ];
    }

    /**
     * @dataProvider provider_condition_status
     * @param $statuses
     * @param $expected
     */
    public function test_condition_条件が指定されていたら条件に合致する行だけが取得できる($statuses, $expected)
    {
        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray([
            ['Q1', 'status'],
            ['1', 'quotafull'],
            ['2', 'complete'],
            ['3', 'screened'],
            ['4', 'quotafull'],
            ['5', 'complete'],
            ['6', 'screened'],
        ])->toCsv()->getPath());

        $csv->getColumnMapper()->setConditions(['status' => ['IN', $statuses]]);

        $Q1List = [];
        foreach ($csv->iterate() as $record) {
            $Q1List[] = $record->get('Q1');
        }
        $this->assertEquals($expected, $Q1List, implode(',', $expected));
    }


    public function test_condition_一致条件と除外条件を両方同時に指定できる()
    {
        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray([
            ['Q1', 'status'],
            ['1', 'complete'], //x除外id & o取得status
            ['2', 'complete'], //o取得id & o取得status
            ['3', 'screened'], //x除外id & x除外status
            ['4', 'complete'], //o取得id & o取得status
            ['5', 'complete'], //o取得id & o取得status
            ['6', 'screened'], //o取得id & x除外status
        ])->toCsv()->getPath());

        $csv->getColumnMapper()->setConditions([
            'status' => ['IN', ['complete']],
            'Q1' => ['NOT_IN', ['1', '3']],
        ]);

        $Q1List = [];
        foreach ($csv->iterate() as $record) {
            $Q1List[] = $record->get('Q1');
        }
        $this->assertEquals(['2', '4', '5'], $Q1List);
    }

    public function test_base_メソッドチェインでset系はかける()
    {
        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray([
            ['Q1', 'Q2', 'Q3', 'Q4', 'status'],
            ['11', '12', '13', '14', 'complete'],
            ['21', '22', '23', '24', 'complete'],
            ['31', '32', '33', '34', 'screened'],
        ])->toCsv()->getPath());
        $columns = $csv->getColumnMapper()->setColumns(['Q3', 'Q1'])->setConditions([
            'Q2' => ['NOT_IN', ['12']],
            'status' => ['IN', ['complete']],
        ])->getColumns();
        $actual = $this->__iterateRecords($csv);

        $this->assertEquals(['Q3', 'Q1'], $columns);
        $this->assertEquals(['23', '21'], $actual[0]->toArray());
        $this->assertCount(1, $actual);
    }

    public function test_format_BOMが先頭についていたら撤去される()
    {
        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray([
            ['Q1'],
            ['あああ'],
        ])->toCsv()->prependUtf8Bom()->getPath());
        $actual = $csv->getColumnMapper()->getColumns();
        $this->assertCount(1, $actual);
        $this->assertEquals(bin2hex('Q1'), bin2hex($actual[0]), " 実際の文字列:" . $actual[0]);
    }

    public function test_base_データが存在_する_場合falseを返す()
    {
        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray([
            ['ID 1', 'Value 1'],
            ['1 1', '値1 1'],
            ['2 2', '値2 2'],
        ])->toCsv()->getPath());

        $this->assertEquals(false, $csv->isEmpty());
    }

    public function test_base_データが存在_しない_場合trueを返す()
    {
        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray([
            ['ID 1', 'Value 1'],
        ])->toCsv()->getPath());

        $this->assertEquals(true, $csv->isEmpty());
    }

    public function test_base_末尾に空文字があっても取得できる()
    {
        $csv = new CsvFileIterator(FileFabricate::fromString(
            "Q1,Q2,Q3\n" .
            "か,,\n"
        )->getPath());
        $actual = [];
        $actual[] = $csv->getColumnMapper()->getColumns();
        foreach ($csv->iterate() as $record) {
            $actual[] = $record->toArray();
        }
        $this->assertEquals([
            ["Q1", "Q2", "Q3"],
            ["か", "", ""],
        ], $actual);
    }

    public function provider_base_空欄のカラムでもデータが取り出せる()
    {
        return [
            [["", "列1", "列2", "列3", "列4", "列5", "列6", "manual_memo", "Q1"]],
            [["ユニークID", "", "列2", "列3", "列4", "列5", "列6", "manual_memo", "Q1"]],
            [["ユニークID", "列1", "", "列3", "列4", "列5", "列6", "manual_memo", "Q1"]],
            [["ユニークID", "列1", "列2", "", "列4", "列5", "列6", "manual_memo", "Q1"]],
            [["ユニークID", "列1", "列2", "列3", "", "列5", "列6", "manual_memo", "Q1"]],
            [["ユニークID", "列1", "列2", "列3", "列4", "", "列6", "manual_memo", "Q1"]],
            [["ユニークID", "列1", "列2", "列3", "列4", "列5", "", "manual_memo", "Q1"]],
            [["ユニークID", "列1", "列2", "列3", "列4", "列5", "列6", "", "Q1"]],
        ];
    }

    /**
     * @dataProvider provider_base_空欄のカラムでもデータが取り出せる
     * @param $columns
     */
    public function test_base_空欄のカラムでもデータが取り出せる($columns)
    {
        $csv = new CsvFileIterator(FileFabricate::from2DimensionalArray([
            $columns,
        ])->toCsv()->getPath());
        //セットしたとおりに取り出せること
        $this->assertEquals($columns, $csv->getColumnMapper()->getColumns(), implode(",", $columns));
    }

    public function provider_format_エンコーディングを強制できる()
    {
        return [
            ["UTF-8"],
            ["SJIS-win"],
        ];
    }

    /**
     * @dataProvider provider_format_エンコーディングを強制できる
     * @param $enc
     */
    public function test_format_エンコーディングを強制できる($enc)
    {
        $path = FileFabricate::fromString(
            "FirstlyANSI,aa\n" .
            str_repeat("1,2\n", 100) .
            mb_convert_encoding("ああ,いい", $enc, "UTF-8")
        )->getPath();
        $csv = new CsvFileIterator($path, $enc); //矯正する

        $this->assertEquals(["FirstlyANSI", "aa"], $csv->getColumnMapper()->getColumns(), $enc);
        $data = [];
        foreach ($csv->iterate() as $record) {
            $data[] = $record->toArray();
        }
        $this->assertEquals(["ああ", "いい"], array_pop($data), $enc); //末尾が文字化けしない;
        foreach ($data as $values) { //他はすべて同一
            $this->assertEquals(["1", "2"], $values, $enc);
        }
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage No such file or directory
     */
    public function test_base_file_does_not_exist()
    {
        new CsvFileIterator("/tmp/NOT_EXIST");
    }

    public function test_base_Excelファイルから取得できる()
    {
        $csv = new CsvFileIterator(__DIR__ . "/test.xlsx");
        $actual = [];
        $actual[] = $csv->getColumnMapper()->getColumns();
        foreach ($csv->iterate() as $record) {
            $actual[] = $record->toArray();
        }
        $this->assertEquals([
            ["らべる1", "らべる2", "らべる3", "らべる4", "らべる5"],
            ["ああ", "かか", "", "", ""],
            ["いい", "きき", "", "", ""],
            ["", "", "", "", ""],
            ["", "", "", "浮島", ""],
            ["", "", "", "", ""],
            ["01", "←0が消えない('利用)", "", "", ""],
            ["01", "←0が消えない(書式:文字列)", "", "", ""],
            ["6", "←計算結果(6)が使われる", "", "", ""],
            ["1,234,567 ", "←書式の影響もうける", "", "", ""],
        ], $actual);
    }
}