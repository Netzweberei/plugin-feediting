<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class passthruContentBlock extends abstractContentBlock
{
    public $template = '%s';

    public function __construct(\herbie\plugin\feediting\classes\FeeditableContent &$editableContent, $mdregex)
    {
        $this->editableContent = $editableContent;
        $this->mdregex = $mdregex;
    }

    protected function insertBlock($currLineUid, $line)
    {
        $this->editableContent->blocks[$currLineUid] = $this->insertEditableTag(
            $currLineUid,
            $this->class,
            'auto',
            MARKDOWN_EOL,
            $line
        );
    }
}