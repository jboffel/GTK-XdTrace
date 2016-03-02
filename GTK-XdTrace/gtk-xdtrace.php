<?php
ini_set('memory_limit', '2048M');

$glade = new GladeXML(dirname(__FILE__) . '/GTK-XdTrace3.glade');

class GTK_XdTrace
{

    protected $glade;

    protected $fileName = NULL;

    protected $steps = array();

    protected $pointer = 1;

    protected $currentFile = array();

    protected $globalFileNameListInOrder = array();

    protected $listOfMapping = array();

    function __construct ($glade)
    {
        $this->glade = $glade;

        $combo = $this->glade->get_widget('steptofile');
        $combo->set_active(0);
        $combo = $this->glade->get_widget('steptostepline');
        $combo->set_active(0);
        $combo = $this->glade->get_widget('mapfolderprefix');
        $combo->set_active(0);
        $combo = $this->glade->get_widget('listofmap');
        $combo->set_active(0);
    }

    public function openFile ($obj)
    {
        $this->fileName = NULL;

        $this->steps = array();

        $this->pointer = 1;

        $this->currentFile = array();

        $this->globalFileNameListInOrder = array();

        $this->listOfMapping = array();

        $this->fileName = $obj->get_filename();

        $this->processTraceFile();

        $this->showFileStepList();

        $this->showFolderToMap();

        $this->startShowStory();
    }

    protected function showFileStepList ()
    {
        $combo = $this->glade->get_widget('steptofile');
        $store = new GtkListStore(Gobject::TYPE_STRING);
        $combo->set_model($store);

        $tmp = array_unique($this->globalFileNameListInOrder);

        foreach ($tmp as $key => $fileName) {
            $combo->append_text($fileName);
        }
    }

    protected function buildListOfFolders($folderList)
    {

        $tmp = array();

        foreach($folderList as $folder) {
            $subRun = dirname($folder);
            $tmp[] = $subRun;
            while (dirname($subRun) != $subRun) {
                $subRun = dirname($subRun);
                $tmp[] = $subRun;
            }
        }

        sort($tmp);

        return array_unique($tmp);
    }

    protected function showFolderToMap ()
    {
        $combo = $this->glade->get_widget('mapfolderprefix');
        $store = new GtkListStore(Gobject::TYPE_STRING);
        $combo->set_model($store);
        $tmp = array_unique($this->globalFileNameListInOrder);
        array_walk($tmp, 'dirname');
        $tmp = array_unique($tmp);
        $tmp = $this->buildListOfFolders($tmp);

        $combo->append_text("List of folder mappable");
        foreach ($tmp as $key => $fileName) {
            $combo->append_text($fileName);
        }

        $combo->set_active(0);
    }

    public function showMapFileChooserBox()
    {
        $combo = $this->glade->get_widget('mapfolderprefix');
        $filename = $combo->get_active_text();

        if ("List of folder mappable" != $filename) {

            $dialog = new GtkFileChooserDialog("Select destination folder", null, Gtk::FILE_CHOOSER_ACTION_SELECT_FOLDER, array(Gtk::STOCK_OK, Gtk::RESPONSE_OK), null);
            $dialog->show_all();
            if ($dialog->run() == Gtk::RESPONSE_OK) {
                $selectedFolder = $dialog->get_filename();
                $selectedFolder = str_replace(DIRECTORY_SEPARATOR, '/', $selectedFolder);
                $this->listOfMapping[$filename] = $selectedFolder;
                $this->fillListOfMap();
            }
            $dialog->destroy();

            $combo->set_active(0);
        }
    }

    public function deleteOneMapping()
    {
        $combo = $this->glade->get_widget('listofmap');
        $map = $combo->get_active_text();

        if ("List of existing map" != $map) {

            $dialog = new GtkMessageDialog($this->glade->get_widget('window1'), Gtk::DIALOG_NO_SEPARATOR, Gtk::MESSAGE_QUESTION, Gtk::BUTTONS_OK_CANCEL, "Delete selected mapping?");
            $dialog->show_all();
            if ($dialog->run() == Gtk::RESPONSE_OK) {
                foreach($this->listOfMapping as $origin => $destination) {
                    if ("$origin -> $destination" == $map) {
                        unset($this->listOfMapping[$origin]);
                        $this->fillListOfMap();
                    }
                }
            } else {
                $combo = $this->glade->get_widget('listofmap');
                $combo->set_active(0);
            }
            $dialog->destroy();
        }
    }

    protected function fillListOfMap()
    {
        $combo = $this->glade->get_widget('listofmap');
        $store = new GtkListStore(Gobject::TYPE_STRING);
        $combo->set_model($store);

        $combo->append_text("List of existing map");

        foreach ($this->listOfMapping as $origin => $destination) {
            $combo->append_text($origin . " -> " . $destination);
        }

        $combo->set_active(0);
    }

    public function showComboStep ()
    {
        $combo = $this->glade->get_widget('steptostepline');
        $stepraw = $combo->get_active_text();

        $step = substr($stepraw, 6, strpos($stepraw, ',') - 6);

        $this->pointer = $step;

        $this->showStoryStep($step);
    }

    public function showStepsInCB2 ()
    {
        $combo = $this->glade->get_widget('steptofile');
        $filename = $combo->get_active_text();

        $combo = $this->glade->get_widget('steptostepline');
        $store = new GtkListStore(Gobject::TYPE_STRING);
        $combo->set_model($store);

        foreach ($this->steps as $key => $step) {
            if ($step['filename'] == $filename)
                $tmp[$key] = $step['line'];
        }

        $tmp = array_unique($tmp);

        foreach ($tmp as $key => $line) {
            $combo->append_text('Step: ' . $key . ', line: ' . $line);
        }
    }

    public function stepin ()
    {
        $this->showStoryStep(++ $this->pointer);
    }

    public function stepover ()
    {
        $currentTreeLevel = $this->steps[$this->pointer]['treeLevel'];
        while (($level = $this->steps[++ $this->pointer]['treeLevel'])) {
            if ($level <= $currentTreeLevel)
                break;
        }
        $this->showStoryStep($this->pointer);
    }

    public function stepreturn ()
    {
        $currentTreeLevel = $this->steps[$this->pointer]['treeLevel'];
        while (($level = $this->steps[++ $this->pointer]['treeLevel'])) {
            if ($level < $currentTreeLevel)
                break;
        }
        $this->showStoryStep($this->pointer);
    }

    public function backin ()
    {
        $this->showStoryStep(-- $this->pointer);
    }

    public function backover ()
    {
        $currentTreeLevel = $this->steps[$this->pointer]['treeLevel'];
        while (($level = $this->steps[-- $this->pointer]['treeLevel'])) {
            if ($level <= $currentTreeLevel)
                break;
        }
        $this->showStoryStep($this->pointer);
    }

    public function backreturn ()
    {
        $currentTreeLevel = $this->steps[$this->pointer]['treeLevel'];
        while (($level = $this->steps[-- $this->pointer]['treeLevel'])) {
            if ($level < $currentTreeLevel)
                break;
        }
        $this->showStoryStep($this->pointer);
    }

    protected function startShowStory ()
    {
        $this->pointer = 1;
        $this->showStoryStep($this->pointer);
    }

    protected function mapTransform($filename)
    {
        foreach($this->listOfMapping as $origin => $destination) {
            if(strstr($filename, $origin)) {
                return str_replace($origin, $destination, $filename);
            }
        }

        return $filename;
    }

    protected function showStoryStep ($step)
    {
        if ($step < 0 || $step > count($this->steps))
            return;

        $filename = $this->mapTransform($this->steps[$step]['filename']);

        $buffer = $this->glade->get_widget('sourcecode')
            ->get_buffer();

        $fileArray = file($filename);

        $buffer->set_text(implode("", $fileArray));

        $this->glade->get_widget('window1')
            ->set_title($this->steps[$step]['filename']);

        $this->currentFile = $fileArray;

        $position = $buffer->get_iter_at_line($this->steps[$step]['line']-1);

        $buffer->place_cursor($position);

        $tag_table = $buffer->get_tag_table();
        $blue_tag = new GtkTextTag();
        $blue_tag->set_property('background', "#ff00ff");
        $tag_table->add($blue_tag);

        $start = $position;
        $length = strlen($fileArray[$this->steps[$step]['line']-1])-1;

        //If couldn't find the file...
        if($length<0)
            $length = 0;

        $end = $buffer->get_iter_at_line_offset($this->steps[$step]['line']-1, $length);

        $buffer->apply_tag($blue_tag, $start, $end);

        $mark = $buffer->create_mark('active_line', $start, false);

        $this->glade->get_widget('sourcecode')
            ->scroll_to_mark($mark, 0.4);

        $buffer = $this->glade->get_widget('memoryusage')
            ->get_buffer();

        $buffer->set_text($this->steps[$step]['memoryUsage'] . ' -> ' . $this->steps[$step]['memoryDelta']);

        $buffer = $this->glade->get_widget('time')
            ->get_buffer();

        $buffer->set_text($this->steps[$step]['timeLaps']);

        $buffer = $this->glade->get_widget('currentinstruction')
            ->get_buffer();

        $buffer->set_text($this->steps[$step]['function']);

        $buffer = $this->glade->get_widget('returncurrentinstruction')
            ->get_buffer();

        $buffer->set_text($this->steps[$step]['returnValue']);

        $this->glade->get_widget('totalsteps')
            ->set_text($step . '/' . count($this->steps));
    }

    public function jump ()
    {
        $step = $this->glade->get_widget('jumptostep')
            ->get_text();

        if ($step < 0 || $step > count($this->steps) || ! is_numeric($step) || $step == '')
            return;

        if ($step > count($this->steps)) {
            $step = count($this->steps);
            $step = $this->glade->get_widget('jumptostep')
                ->set_text(count($this->steps));
        }

        $this->showStoryStep($step);
        $this->pointer = $step;
    }

    protected function processTraceFile ()
    {
        $this->steps = array();

        $handle = @fopen($this->fileName, "r");
        if ($handle) {
            $i = 0;
            $padding = 0;
            $offset = 0;
            while (($buffer = fgets($handle)) !== false) {
                $res = preg_match('/^\s+([0-9.]+)\s+([0-9]+)\s+([0-9+-]+)(\s+)->\s+(.*)\s(.*):([0-9]+).*$/', $buffer,
                    $steps);
                if ($res) {
                    $this->steps[] = array(
                        'timeLaps' => $steps[1],
                        'memoryUsage' => $steps[2],
                        'memoryDelta' => $steps[3],
                        'treeLevel' => (strlen($steps[4]) - 3) / 2,
                        'function' => $steps[5],
                        'filename' => str_replace(DIRECTORY_SEPARATOR, '/', $steps[6]),
                        'line' => $steps[7],
                        'line2' => $i,
                        'returnValue' => NULL
                    );
                    $this->globalFileNameListInOrder[] = str_replace(DIRECTORY_SEPARATOR, '/', $steps[6]);
                }
                $res = preg_match('/^(\s+([0-9.]+)\s+([0-9]+)\s+)>=>\s(.*)$/', $buffer, $retValue);
                if ($res) {
//                     $this->retValue[] = array(
//                         'treeLevel' => (strlen($retValue[1]) - $padding) / 2,
//                         'retValue' => $retValue[2],
//                         'line' => $i
//                     );
                    for ($w = count($this->steps) - 1; $w > 0; $w --) {
                        $treeLevel = $this->steps[$w]['treeLevel'];
                        $otherTreeLevel = (strlen($retValue[1]) - $padding) / 2;
                        if ($treeLevel == $otherTreeLevel) {
                            break;
                        }
                    }
                    $this->steps[$w]['returnValue'] = $retValue[4];
                }
                if ($i == 1) {
                    $padding = strlen($buffer) - strlen(strstr($buffer, '->'));
                }
                $i ++;
            }
            if (! feof($handle)) {
                die("Error: unexpected fgets() fail\n");
            }
            fclose($handle);
        }
    }
}

$glade->signal_autoconnect_instance(new GTK_XdTrace($glade));

$glade->get_widget('window1')
    ->connect_simple('destroy', array(
        'Gtk',
        'main_quit'
    ));

// Start the main loop
Gtk::main();

?>
