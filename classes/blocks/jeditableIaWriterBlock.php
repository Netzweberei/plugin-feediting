<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class jeditableIaWriterBlock extends inlineButWriteRegex0ContentBlock
{
    protected $blockType = 'iaWriterBlock';
    protected $mdregex = '/(?<=^)\\/_.*\.md/s';
    protected $template = '<iframe seamless id="###id###" onload="iframeLoaded(\'###id###\')" src="/###src###?editor=iframe" style="width: 100%%; border-width: 0px;" scrolling="no">|</iframe>';
    protected $dataregex = '/(\/(_.*)\.md)/';
    protected $editingMaskMap = [];
    protected $tmplstrSeparator = '|';

    protected function insertBlock($currLineUid, $line)
    {
        // search for the block's data
        preg_match($this->dataregex, $line, $b_data);

        // support default routes to 'index'-files
        $ret    = $this->withCmdSave !== false ? $b_data[1] : $b_data[2];

        $this->editableContent->blocks[$currLineUid] = $this->insertEditableTag(
            $currLineUid,
            $this->class,
            'auto',
            '',
            $ret
        );
    }

    public function insertEditableTag($contentUid, $contentClass, $mode = 'inline', $eol = PHP_EOL, $ret = null)
    {
        $class  = $contentClass;
        $id     = $contentClass.'#'.$contentUid;

        $openBlock = strtr($this->openContainer(), [
                '###id###' => $id,
                '###class###' => $class,
                '###src###' => dirname($ret)
            ]);
        $stopBlock = $this->closeContainer();

        $startmark = '<!-- ###'.$id.'### Start -->';
        $this->editableContent->plugin->setReplacement($startmark, $openBlock);

        $stopmark = '<!-- ###'.$this->blockType.'### Stop -->';
        $this->editableContent->plugin->setReplacement($stopmark, $stopBlock);

        return $eol.$startmark.$eol.$ret.PHP_EOL.$eol.$stopmark.$eol.PHP_EOL;
    }
}