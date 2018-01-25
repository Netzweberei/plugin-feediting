<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;

/* Do nothing special here (any more), instead delegate the task to
 * the iawritershortcodePlugin, which 'knows' more about these kinds
 * of blocks!
 */
class jeditableIaWriterBlock extends inlineButWriteRegex0ContentBlock
{
    protected $blockType = 'iaWriterBlock';
    protected $mdregex = '/(?<=^)\\/_.*\.md/s';
    protected $template = '<div>|</div>';
    protected $dataregex = '/(\/_.*\.md)/';
    protected $editingMaskMap = [];
    protected $tmplstrSeparator = '|';

    protected function insertBlock($currLineUid, $line)
    {
        preg_match($this->dataregex, $line, $b_data);

        $this->editableContent->blocks[$currLineUid] = $this->insertEditableTag(
            $currLineUid,
            $this->class,
            'auto',
            '',
            $b_data[1]
        );
    }

    public function insertEditableTag($contentUid, $contentClass, $mode = 'inline', $eol = PHP_EOL, $slug = '')
    {
        $class = $contentClass;
        $id = $contentClass.'#'.$contentUid;

        $openBlock = strtr(
            $this->openContainer(),
            [
                '###id###' => $id,
                '###class###' => $class,
                '###src###' => strtr($slug, ['/index' => ''])
            ]
        );
        $stopBlock = $this->closeContainer();

        $startmark = '<!-- ###'.$id.'### Start -->';
        $this->editableContent->plugin->setReplacement($startmark, $openBlock);

        $stopmark = '<!-- ###'.$this->blockType.'### Stop -->';
        $this->editableContent->plugin->setReplacement($stopmark, $stopBlock);

        return $eol.$startmark.$eol.PHP_EOL.$slug.PHP_EOL.$eol.$stopmark.$eol.PHP_EOL;
    }
}