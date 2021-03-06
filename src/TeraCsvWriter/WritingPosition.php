<?php
namespace waterada\TeraCsvWriter;

/**
 * Class ReadingPosition
 *
 * 読み込みを一旦中断して、再開したい場合に、中断位置をセッションなどに保存する際の単位となるクラス。
 */
class WritingPosition
{
    /** @var string */
    public $path;

    /** @var int 現在処理中の行番号(0～) ※0 ならまだ未処理。1はラベル行。データは2～。除外するレコードも行としてカウントされる。 */
    public $rownum;

    public $isFinished;

    public function __construct($path)
    {
        $this->path = $path;
        $this->rownum = 0;
        $this->isFinished = false;
    }

    public function isMaking()
    {
        return ($this->isFinished == false);
    }

    public function getPath()
    {
        return $this->path;
    }

    public function getRownum()
    {
        return $this->rownum;
    }

    public function getNextRownum()
    {
        return $this->rownum + 1;
    }
}