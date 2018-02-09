<?php

/**
 * This file is part of Herbie.
 *
 * (c) Thomas Breuss <www.tebe.ch>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace herbie\plugin\feediting\classes;

use herbie\plugin\feediting\FeeditingPlugin;
use Herbie\DI;

class SirTrevorContent extends FeeditableContent
{

    public $collectAllChanges = true;

    public $reloadPageAfterSave = true;

    protected $eob = PHP_EOL;

    protected $contentBlocks = [];

    protected $contentContainer = '{"data":[%s{}]}';

    protected $segmentLoadedMsg = '';

    protected function registerContentBlocks()
    {
        $this->contentBlocks = [
            new blocks\sirTrevorHeadingBlock($this),
            new blocks\sirTrevorWidgetBlock($this),
            new blocks\sirTrevorImageBlock($this),
            new blocks\sirTrevorSmartyimageBlock($this),
            new blocks\sirTrevorHrBlock($this),
            new blocks\sirTrevorVideoBlock($this),
            new blocks\sirTrevorImagineBlock($this),
            new blocks\sirTrevorMidpageBlock($this),
            new blocks\sirTrevorTextBlock($this),
        ];
    }

    /**
     * @param array $moreExcludeBlocks
     */
    protected function registerPassthruBlocks($moreExcludeBlocks=[])
    {
        $moreExcludeBlocks = [
            new blocks\passthruContentBlock($this, '/^'.$this->plugin->editableEmptySegmentContent.'.*$/')
        ];
        parent::registerPassthruBlocks($moreExcludeBlocks);
    }

    /**
     * @return string
     */
    public function save()
    {
        return 'save';
    }

    /**
     * @param null $path
     */
    public function getEditablesCssConfig($path = null)
    {
        $this->plugin->includeIntoHeader($path.'libs/sir-trevor-js/sir-trevor-icons.css');
        $this->plugin->includeIntoHeader($path.'libs/sir-trevor-js/sir-trevor.css');
    }

    /**
     * @param null $path
     */
    public function getEditablesJsConfig($path = null)
    {
        $this->plugin->includeAfterBodyStarts('<form method="post">');
        $this->plugin->includeAfterBodyStarts('<input type="hidden" name="cmd" value="save">');
        $this->plugin->includeBeforeBodyEnds('</form>');

        $uploadPath = DIRECTORY_SEPARATOR.strtr(
                $this->plugin->alias->get(dirname($this->plugin->page->getPath())),
                [
                    $this->plugin->alias->get('@page') => '',
                ]
            );

        foreach ($this->plugin->segments as $segmentid => $segment) {

            $editorId       = strtr($segmentid, ['[' => '', ']' => '']);
            $editorSelector = strtr($segmentid, ['[' => '\\\[', ']' => '\\\]']);
            $this->plugin->includeBeforeBodyEnds(<<<EOT
<script type="text/javascript" charset="utf-8">
    window.editor$editorId = new SirTrevor.Editor({
        el: $(".sirtrevor-$editorSelector"),
        blockTypes: [
          "Heading",
          "Text",
//          "List",
//          "Quote",
          "Image",
          "Video",
//          "Tweet",
//          "Widget"
        ],
        defaultType: "Text"
      });
      SirTrevor.setDefaults({
        uploadUrl: "$uploadPath?cmd=upload"
      });
</script>
EOT
            );
        }

        $this->plugin->includeBeforeBodyEnds($path.'libs/ios-html5-drag-drop-shim-master/ios-drag-drop.js');
        $this->plugin->includeAfterBodyStarts(
            '<script type="text/javascript" charset="utf-8">var iosDragDropShim = { enableEnterLeave: true, requireExplicitDraggable: true, holdToDrag: 300 }</script>'
        );
        $this->plugin->includeBeforeBodyEnds($path.'libs/sir-trevor-js/locales/de.js');
        $this->plugin->includeBeforeBodyEnds($path.'libs/sir-trevor-js/sir-trevor.js');
        $this->plugin->includeBeforeBodyEnds($path.'libs/Eventable/eventable.js');
        $this->plugin->includeBeforeBodyEnds($path.'libs/underscore/underscore.js');
        if (false === $this->pluginConfig['dontProvideJquery']) {
            $this->plugin->includeBeforeBodyEnds($path.'libs/jquery/jquery-1.8.2.js');
        }
    }

    /**
     * @param $segmentId
     * @param $content
     * @return string
     */
    public function getEditableContainer($segmentId, $content)
    {
        $ret = '';
        $content = strtr($content,[
                "\r" => '',
                "\n" => '',
        ]);

        if ($segmentId == 0) {
            $ret .= '<div class="st-submit"><input type="submit" value="click to save changes" class="top" ><input type="hidden" name="id" value="sirtrevor-'.$segmentId.'" ></input></div>';
        }

        if ($this->plugin->cmd == 'editWidget') {
            $ret .= '<input type="hidden" name="cmd" value="saveWidget" />';
            $ret .= '<input type="hidden" name="name" value="'.$_REQUEST['name'].'" />';
        }

        $ret .= '<textarea name="sirtrevor-'.$segmentId.'" class="sirtrevor-'.$segmentId.'" markdown="1">'
             .strtr(sprintf($this->contentContainer,$content),['MARKDOWN_EOL' => '<br>'])
             .'</textarea>';
        return $ret;
    }

    /**
     * @param $elemId
     * @return string
     */
    public function encodeEditableId($elemId)
    {
        return 'sirtrevor-'.$elemId;
    }

    /**
     * @param $elemId
     * @return array
     */
    public function decodeEditableId($elemId)
    {
        list($contenttype, $currsegmentid) = explode('-', $elemId);
        return array(
            'elemid' => $elemId,
            'segmentid' => $currsegmentid,
            'contenttype' => 'sirtrevor',
        );
    }

    /**
     * @param bool $withEob
     * @return string
     */
    public function getSegment($withEob = true)
    {
        $ret = parent::getSegment($withEob);
        // remove tabs, grid-relicts etc...
        return strtr($ret,[
            '----' => '',
            "\t" => '',
        ]);
    }

    /**
     * @param $id of block i.e. segment
     * @return string (whole segment as one big block)|null
     */
    public function getContentBlockById($id = null)
    {
        if ($this->blocks) {
            return implode($this->blocks);
        }
        return null;
    }

    /**
     * @param $id of block i.e. segment
     * @param $json new ST-content
     * @return boolean success
     */
    public function setContentBlockById($id = false, $json)
    {
        if ($id) {
            // empty whole segment
            $this->blocks = [];
            // get data (from $_POST)
            $replacement = implode($this->json2array($json));
            // Reindex all blocks
            $newBlocks = $this->plugin->renderRawContent($replacement, $this->getFormat(), true);
            $this->setContentBlocks($newBlocks);

            return true;
        }
        return false;
    }

    /**
     * @param $json
     * @return array
     */
    private function json2array($json)
    {
        $blocks = array();
        $content= json_decode($json);

        if (isset($content->data)) {
            foreach ($content->data as $_block) {
                $blocks[] = PHP_EOL;

                if (isset($_block->type)) {
                    switch ($_block->type) {
                        case 'widget':
                            $blocks[] = '{{widget(\''.basename(
                                $_block->data->selected
                            ).'\', '.$_block->data->slide.')}}'.PHP_EOL;
                            break;
                        case 'video':
                            $blocks[] = '{{ youtube("'.$_block->data->remote_id.'", 480, 320) }}'.PHP_EOL;
                            break;
                        case 'image':
                            $blocks[] = '!['.basename(
                                $_block->data->file->url
                            ).']('.$_block->data->file->url.')'.PHP_EOL;
                            break;
                        case 'heading':
                            $test = strtr(trim($_block->data->text),[
                                    PHP_EOL => '',
                                    '\\' => '',
                            ]);
                            // show headings in md-format
                            if (substr($test, 0, 1) !== '#') {
                                $test = '#'.$test;
                            }
                            $blocks[] = html_entity_decode($test, ENT_QUOTES).PHP_EOL;
                            break;
                        case 'text':
                        default:
                            $test = strtr($_block->data->text,[
                                '\n' => PHP_EOL,
                                '\\' => ''
                            ]).PHP_EOL;
                            $blocks[] = html_entity_decode($test, ENT_QUOTES).PHP_EOL;
                            break;
                    }
                }

            }
        }
        return $blocks;
    }

    public function upload()
    {
        if ($_FILES) {
            $pwd = DI::get('Alias')->get(DI::get('Page')->getPath());
            $uploaddir = dirname($pwd);
            $uploadfile = filter_var(
                $uploaddir.DS.basename($_FILES['attachment']['name']['file']),
                FILTER_SANITIZE_URL
            );

            if (move_uploaded_file($_FILES['attachment']['tmp_name']['file'], $uploadfile)) {
                $relpath = strtr($uploadfile, array($this->plugin->alias->get('@site') => '@site'));
                $sirtrevor = '{ "file": { "url" : "'.$relpath.'" } }';
                die($sirtrevor);
            }
        }
    }

//    public function getWidgetByName()
//    {
//        $widgetsExtension = new \herbie\plugin\widgets\classes\WidgetsExtension($this->plugin->app);
//        $widgetsExtension->renderWidget($_REQUEST['name'], $_REQUEST['slide'], $fireEvents = true);
//
//        return $_cmd = 'bypass';
//    }
//
//    public function saveWidget()
//    {
//        $widgetsExtension = new \herbie\plugin\widgets\classes\WidgetsExtension($this->plugin->app);
//        $widgetsExtension->renderWidget($_REQUEST['name'], $_REQUEST['slide'], $fireEvents = true);
//
//        return $_cmd = 'bypass';
//    }
//
//    public function loadAvailableWidgets()
//    {
//        // @todo: Don't realize widgets through additional extension, but use iawritershortcodes instead!
//        // - embed iawritershortcode, i.e. link to a hidden subpage
//        // - configure subpage.md to use distinct layout (stored in layouts/theme/widgets)
//        // - configure subpage.md initially to stay hidden!
//        // - store subpage in its own _folder/index.md
//        //$widgetsExtension = new \herbie\plugin\widgets\classes\WidgetsExtension($this->plugin->app);
//        //$ret['data'] = $widgetsExtension->getAvailableWidgets('@site/widgets');
//
//        $ret = ['type' => 'widget'];
//        $ret['data'] = [
//            'available' => [
//                0 => [
//                    'name' => 'Slider',
////                    'icon' => 'widget',
////                    'type' => '_slider',
//                    'uri' => '/_slider/index.md',
//                ],
//                1 => [
//                    'name' => 'Box',
////                    'icon' => 'widget',
////                    'type' => '_box',
//                    'uri' => '/_box/index.md',
//                ],
//            ],
//        ];
//
//        die(json_encode($ret));
//    }
//
//    public function copySelectedWidget()
//    {
//        $widgetsExtension = new \herbie\plugin\widgets\classes\WidgetsExtension($this->plugin->app);
//
//        $ret = [];
//        $ret['selected'] = $widgetsExtension->doCopyWidget($_REQUEST['widget'], '@site/widgets');
//
//        die(json_encode($ret));
//    }

    /**
     * @return string
     */
    public function getLineFeedMarker()
    {
        return $this->plugin->getLineFeedMarker($this->getFormat());
    }

} 