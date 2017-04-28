<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;

class arrayContentBlock extends abstractContentBlock
{
    protected function insertBlock($currLineUid, $line)
    {
        // search for the block's data
        preg_match($this->dataregex, $line, $b_data);
        if (count($b_data) > 1) {
            array_shift($b_data);
        }

        $this->editableContent->blocks[$currLineUid - 1] = $this->insertEditableTag(
            $currLineUid,
            $this->class,
            'start',
            MARKDOWN_EOL
        );
        $this->editableContent->blocks[$currLineUid] = reset($b_data);
        $this->editableContent->blocks[$currLineUid + 1] = $this->insertEditableTag(
            $currLineUid,
            $this->class,
            'stop',
            MARKDOWN_EOL
        );
    }
}