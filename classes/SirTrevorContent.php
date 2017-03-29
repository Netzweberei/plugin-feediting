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

    public $editableEmptySegmentContent = PHP_EOL;

    protected $eob = '';

    protected $contentBlocks = [
        "headingBlock" => [
            "template" => '{"type":"heading","data":{"text":"%s"}},',
            "mdregex" => '/^#/',
            "dataregex" => '/(.*)/',
            "editingMaskMap" => ['"' => '\"'],
            "insert" => 'ownline'
        ],
        "widgetBlock" => [
            "template" => '{"type":"widget","data":{"selected":"%s", "slide":"%2$d"}},',
            "mdregex" => '/^\{\{\s?widget/',
            "dataregex" => '/(^.*\([\'\"]{1}(.*)[\'\"]{1}\,?\s?([0-9]*)\).*$)/',
            "insert" => 'ownlineButWriteRegex0'
        ],
        "imageBlock" => [
            "template" => '{"type":"image","data":{"file":{"url":"%s"}}},',
            "mdregex" => '/^\!\[/',
            "dataregex" => '/(^.*\((.*)\).*$)/',
            "editingMaskMap" => ['/site' => '@site'],
            "insert" => 'ownlineButWriteRegex0'
        ],
        "smartyimageBlock" => [
            "template" => '{"type":"image","data":{"file":{"url":"%s"}}},',
            "mdregex" => '/^\[image\s/',
            "dataregex" => '/(^\[image\s([^\s]+)\s.*$)/',
            "insert" => 'ownlineButWriteRegex0'
        ],
        "hrBlock" => [
            "template" => '{"type":"text","data":{"text":"%s--------------------"}},',
            "mdregex" => '/^---*|___*$/',
            "dataregex" => '//',
            "insert" => 'ownline'
        ],
        "videoBlock" => [
            "template" => '{"type":"video","data":{"source":"youtube","remote_id":"%1$s"}},',
            "mdregex" => '/^\{\{ youtube/',
            "dataregex" => '/(^{\{ youtube\("(.*)".*$)/',
            "insert" => 'ownlineButWriteRegex0',
            "tmplstrSeparator" => '%1$s'
        ],
        "imagineBlock" => [
            "template" => '{"type":"image","data":{"file":{"url":"media/%s"}}},',
            "mdregex" => '/^\<img/',
            "dataregex" => '/\'([a-zA-Z\.0-9\-\_]*)\'/',
            "insert" => 'ownline'
        ],
        "midpageBlock" => [
            "type" => 'textBlock',
            "template" => '{"type":"text","data":{"text":"%s"}},',
            "mdregex" => '/^\[block\]/',
            "mdregexStop" => '/^\[\/block\]/',
            "dataregex" => '/.*/',
            "editingMaskMap" => ['"' => '\"'],
            "insert" => 'multiline'
        ],
        "textBlock" => [
            "type" => 'textBlock',
            "template" => '{"type":"text","data":{"text":"%s"}},',
            "mdregex" => '/.*/',
            "mdregexStop" => '/^$/',
            "dataregex" => '/.*/',
            "editingMaskMap" => [
                '"' => '\"'
            ],
            "insert" => 'multiline'
        ],
    ];

    protected $contentContainer = '{"data":[%s{}]}';

    protected $segmentLoadedMsg = '';

    public function save()
    {
        return 'save';
    }

    public function getEditablesCssConfig($path = null)
    {
        $this->plugin->includeIntoHeader($path . 'libs/jquery_fancybox/jquery.fancybox.css');
        $this->plugin->includeIntoHeader($path . 'libs/sir-trevor-js/sir-trevor-icons.css');
        $this->plugin->includeIntoHeader($path . 'libs/sir-trevor-js/sir-trevor.css');
    }

    public function getEditablesJsConfig($path = null)
    {
        $this->plugin->includeAfterBodyStarts('<form method="post">');
        $this->plugin->includeAfterBodyStarts('<input type="hidden" name="cmd" value="save">');
        $this->plugin->includeBeforeBodyEnds('</form>');

        $uploadPath = DIRECTORY_SEPARATOR . strtr(
                $this->plugin->alias->get(dirname($this->plugin->page->getPath())),
                [
                    $this->plugin->alias->get('@page') => ''
                ]
            );

        foreach ($this->plugin->segments as $segmentid => $segment) {
            $this->plugin->includeBeforeBodyEnds(
                '<script type="text/javascript" charset="utf-8">' .
                '
                      window.editor' . strtr($segmentid, ['[' => '', ']' => '']) . ' = new SirTrevor.Editor({
        el: $(".sirtrevor-' . strtr($segmentid, ['[' => '\\\[', ']' => '\\\]']) . '"),
        blockTypes: [
          "Text",
          "Heading",
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
        uploadUrl: "' . $uploadPath . '?cmd=upload"
      });
' .
                '</script>'
            );
        }
        $this->plugin->includeBeforeBodyEnds($path . 'libs/ios-html5-drag-drop-shim-master/ios-drag-drop.js');
        $this->plugin->includeAfterBodyStarts('<script type="text/javascript" charset="utf-8">var iosDragDropShim = { enableEnterLeave: true }</script>');
//        $this->plugin->includeBeforeBodyEnds($path . 'libs/jquery_fancybox/jquery.fancybox.js');
        $this->plugin->includeBeforeBodyEnds($path . 'libs/sir-trevor-js/locales/de.js');
        $this->plugin->includeBeforeBodyEnds($path . 'libs/sir-trevor-js/sir-trevor.js');
        $this->plugin->includeBeforeBodyEnds($path . 'libs/Eventable/eventable.js');
        $this->plugin->includeBeforeBodyEnds($path . 'libs/underscore/underscore.js');
        if (false === $this->pluginConfig['dontProvideJquery']) {
            $this->plugin->includeBeforeBodyEnds($path . 'libs/jquery/jquery-1.8.2.js');
        }
    }

    public function getEditableContainer($segmentId, $content)
    {
        $ret = '';
        $content = strtr(
            $content,
            [
                PHP_EOL => ''
            ]
        );

        if ($segmentId == 0) {
            $ret .= '<div class="st-submit"><input type="submit" value="click to save changes" class="top" ><input type="hidden" name="id" value="sirtrevor-' . $segmentId . '" ></input></div>';
        }
        if ($this->plugin->cmd == 'editWidget') {
            $ret .= '<input type="hidden" name="cmd" value="saveWidget" />';
            $ret .= '<input type="hidden" name="name" value="' . $_REQUEST['name'] . '" />';
        }
        $ret .= '<textarea name="sirtrevor-' . $segmentId . '" class="sirtrevor-' . $segmentId . '" markdown="1">' . sprintf(
                $this->contentContainer,
                $content
            ) . '</textarea>';

        $ret = strtr(
            $ret,
            [
                'MARKDOWN_EOL' => '<br>'
            ]
        );

        return $ret;
    }

    public function encodeEditableId($elemId)
    {
        return 'sirtrevor-' . $elemId;
    }

    public function decodeEditableId($elemId)
    {
        list($contenttype, $currsegmentid) = explode('-', $elemId);

        return array(
            'elemid' => $elemId,
            'segmentid' => $currsegmentid,
            'contenttype' => 'sirtrevor'
        );
    }

    public function getSegment($eob = true)
    {
        $ret = parent::getSegment(true);
        // remove tabs, grid-relicts etc...
        return strtr($ret, [
           '----' => '',
           "\t" => ''
        ]);
    }

    /**
     * @param $id of block i.e. segment
     * @return string $ret whole segment as one big block
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

    private function json2array($json)
    {
        $blocks = array();
        $content = json_decode($json);

        if (isset($content->data)) {
            foreach ($content->data as $_block) {
                $blocks[] = PHP_EOL;
                if (isset($_block->type)) {
                    switch ($_block->type) {
                        case 'widget':
                            $blocks[] = '{{widget(\'' . basename(
                                    $_block->data->selected
                                ) . '\', ' . $_block->data->slide . ')}}' . PHP_EOL;
                            break;
                        case 'video':
                            $blocks[] = '{{ youtube("' . $_block->data->remote_id . '", 480, 320) }}' . PHP_EOL;
                            break;
                        case 'image':
                            $blocks[] = '![' . basename(
                                    $_block->data->file->url
                                ) . '](' . $_block->data->file->url . ')' . PHP_EOL;
                            break;
                        case 'heading':
                            $test = strtr(
                                trim($_block->data->text),
                                [
                                    PHP_EOL => '',
                                    '\\' => ''
                                ]
                            );
                            // show headings in md-format
                            if (substr($test, 0, 1) !== '#') {
                                $test = '#' . $test;
                            }
                            $blocks[] = html_entity_decode($test, ENT_QUOTES) . PHP_EOL;
                            break;
                        case 'text':
                        default:
                            $test = strtr(
                                    $_block->data->text,
                                    [
                                        '\n' => PHP_EOL,
                                        '\\' => '' // demask before saving
                                    ]
                                ) . PHP_EOL;
                            $blocks[] = html_entity_decode($test, ENT_QUOTES) . PHP_EOL;
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
                $uploaddir . DS . basename($_FILES['attachment']['name']['file']),
                FILTER_SANITIZE_URL
            );

            if (move_uploaded_file($_FILES['attachment']['tmp_name']['file'], $uploadfile)) {
                $relpath = strtr($uploadfile, array($this->plugin->alias->get('@site') => '@site'));
                $sirtrevor = '{ "file": { "url" : "' . $relpath . '" } }';
                die($sirtrevor);
            }
        }
    }

    public function getWidgetByName()
    {
        $widgetsExtension = new \herbie\plugin\widgets\classes\WidgetsExtension($this->plugin->app);
        $widgetsExtension->renderWidget($_REQUEST['name'], $_REQUEST['slide'], $fireEvents = true);
        return $_cmd = 'bypass';
    }

    public function saveWidget()
    {
        $widgetsExtension = new \herbie\plugin\widgets\classes\WidgetsExtension($this->plugin->app);
        $widgetsExtension->renderWidget($_REQUEST['name'], $_REQUEST['slide'], $fireEvents = true);
        return $_cmd = 'bypass';
    }

    public function loadAvailableWidgets()
    {

//        $widgetsExtension = new \herbie\plugin\widgets\classes\WidgetsExtension($this->plugin->app);
        $ret = ['type' => 'widget'];
        $ret['data'] = [
            'available' => [
                0 => [
                    'name' => 'box 1',
                    'icon' => 'widget',
                    'type' => '_box',
                    'uri' => '_box'
                ],
                1 => [
                    'name' => 'box 2',
                    'icon' => 'widget',
                    'type' => '_box',
                    'uri' => '_box'
                ]
            ]
        ];
//        $ret['data'] = $widgetsExtension->getAvailableWidgets('@site/widgets');

        die(json_encode($ret));
    }

    public function copySelectedWidget()
    {
        $widgetsExtension = new \herbie\plugin\widgets\classes\WidgetsExtension($this->plugin->app);

        $ret = [];
        $ret['selected'] = $widgetsExtension->doCopyWidget($_REQUEST['widget'], '@site/widgets');

        die(json_encode($ret));
    }

    protected function getLineFeedMarker()
    {
        return $this->plugin->getLineFeedMarker($this->getFormat());
    }

} 