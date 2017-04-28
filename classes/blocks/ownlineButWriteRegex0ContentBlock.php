<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class ownlineButWriteRegex0ContentBlock extends abstractContentBlock
{
    protected function insertBlock($currLineUid, $line)
    {
        // search for the block's data
        preg_match($this->dataregex, $line, $b_data);
        if (count($b_data) > 1) {
            array_shift($b_data);
        }

        $this->editableContent->blocks[$currLineUid] = $this->insertEditableTag(
            $currLineUid,
            $this->class,
            'auto',
            '',
            $this->withCmdSave !== false ? reset($b_data) : array_slice($b_data, 1)
        );
    }
}