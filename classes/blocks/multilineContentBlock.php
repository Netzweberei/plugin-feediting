<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class multilineContentBlock extends abstractContentBlock
{
    protected $insert = 'multiline';

    public function filterLine($lines, $ctr, $offset)
    {
        $ctlines = count($lines);
        $currLineUid = $this->editableContent->calcLineIndex($ctr, $offset, $this->editableContent->currSegmentId);

        $this->withCmdSave = strpos($this->editableContent->plugin->cmd, 'save') !== false ? true : false;
        $this->class = $this->editableContent->pluginConfig['editable_prefix'].$this->editableContent->format.'-'.$this->editableContent->currSegmentId;

        // looking for special content which need its own block
        if (preg_match($this->mdregex, $lines[$ctr])) {

            $this->editableContent->currBlockId = $currLineUid;

            $this->editableContent->blocks[$currLineUid - 1] = ($this->editableContent->currSegmentId === false)
                ? ''
                : $this->insertEditableTag($currLineUid, $this->class, 'start', MARKDOWN_EOL);

            do {

                $line = $lines[$ctr];

                // editing pure text still requires to us mask some chars?
                if ($this->withCmdSave === false && isset($this->editingMaskMap)) {
                    $line = strtr($line, $this->editingMaskMap);
                }

                if (!isset($this->editableContent->blocks[$currLineUid])) {

                    $this->editableContent->blocks[$currLineUid] = $line;

                } else {

                    $this->editableContent->blocks[$currLineUid] .= $line;
                }
                $this->editableContent->blocks[$currLineUid] .= $this->editableContent->getLineFeedMarker();

                $ctr++;
            } while (($ctr < $ctlines) && (preg_match(@$this->mdregexStop, $lines[$ctr]) == 0));
        }

        return $ctr;
    }
}