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

    protected $currentProgress = 0;

    protected $progressBar = null;

    protected $dialog = null;

    protected $folderToMap = array();

    protected $folderToMapBkP = array();

    protected $time = 0;

    protected $previousFile = array();

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

        $this->currentProgress = 0;

        $this->progressBar = null;

        $this->dialog = null;

        $this->time = 0;

        $this->folderToMap = array();

        $this->folderToMapBkP = array();

        $this->fileName = $obj->get_filename();

        $this->previousFile = array();

        $this->startProgressBar();

        $this->processTraceFile();

        $this->stopProgressBar();

        $this->showFileStepList();

        $this->showFolderToMap();

        $this->fillListOfMap();

        $this->startShowStory();
    }

    protected function showFileStepList ()
    {
        $combo = $this->glade->get_widget('steptofile');
        $store = new GtkListStore(Gobject::TYPE_STRING);
        $combo->set_model($store);

        $tmp = array_merge(array_unique($this->globalFileNameListInOrder));

        foreach ($tmp as $key => $fileName) {
            $combo->append_text($fileName);
        }

        $combo = new GtkEntryCompletion();

        $entry = $this->glade->get_widget('selectfolderautocomplete');

        $entry->set_completion($combo);

        $model = new GtkListStore(Gobject::TYPE_STRING);

        $combo->set_model($model);
        $combo->set_text_column(0);
        $combo->set_match_func(array(&$this, "matchFunc"));
        $combo->set_popup_set_width(false);
        $combo->connect('match-selected', array(&$this, 'onMatchSelectedBkP'));

        foreach($tmp as $key => $fileName) {
            $model->append(array($fileName));
        }

        $this->folderToMapBkP = $tmp;
    }

    public function onMatchSelected($widget, $model, $iter)
    {
        $folder = $model->get_value($iter, 0);

        $combo = $this->glade->get_widget('mapfolderprefix');

        $index = array_search($folder, $this->folderToMap);

        if ($index === false) {
            return;
        }

        $combo->set_active($index+1);

        $this->showMapFileChooserBox();

    }

    public function onMatchSelectedBkP($widget, $model, $iter)
    {
        $folder = $model->get_value($iter, 0);

        $combo = $this->glade->get_widget('steptofile');

        $index = array_search($folder, $this->folderToMapBkP);

        if ($index === false) {
            return;
        }

        $combo->set_active($index);

        $this->showStepsInCB2();

    }

    function matchFunc($completion, $key_string, $iter) {
        $model = $completion->get_model();

        if(stristr($model->get_value($iter, 0), $key_string)) {
            return true;
        }
        return false;
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

        return array_merge(array_unique($tmp));
    }

    protected function myDirname($value, $key) {
        return dirname($value);
    }

    protected function showFolderToMap ()
    {
        $combo = $this->glade->get_widget('mapfolderprefix');
        $store = new GtkListStore(Gobject::TYPE_STRING);
        $combo->set_model($store);
        $tmp = array_unique($this->globalFileNameListInOrder);
        array_walk($tmp, array(&$this, 'myDirname'));
        $tmp = array_unique($tmp);
        $tmp = $this->buildListOfFolders($tmp);

        $combo->append_text("List of folder mappable");
        foreach ($tmp as $key => $fileName) {
            $combo->append_text($fileName);
        }

        $combo->set_active(0);

        $combo = new GtkEntryCompletion();

        $entry = $this->glade->get_widget('selectfolderinlist');

        $entry->set_completion($combo);

        $model = new GtkListStore(Gobject::TYPE_STRING);
        $combo->set_model($model);
        $combo->set_text_column(0);
        $combo->set_match_func(array(&$this, "matchFunc"));
        $combo->set_popup_set_width(false);
        $combo->connect('match-selected', array(&$this, 'onMatchSelected'));

        foreach($tmp as $key => $fileName) {
            $model->append(array($fileName));
        }

        $this->folderToMap = $tmp;
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

        if ($this->previousFile['name'] != $filename) {
            $this->previousFile['name'] = $filename;

            $lang_mgr = new GtkSourceLanguagesManager();
            $lang = $lang_mgr->get_language_from_mime_type("application/x-php");
            $buffer = GtkSourceBuffer::new_with_language($lang);
            $buffer->set_highlight(1);
            $view = $this->glade->get_widget('sourcecode');
            $view->set_buffer($buffer);


            $fileArray = @file($filename);

            $this->previousFile['fileArray'] = $fileArray;

            if (!is_array($fileArray)) {
                $buffer->set_text("");
            } else {
                $buffer->set_text(implode("", $fileArray));
            }

            $view->set_show_line_numbers(1);

            $tag_table = $buffer->get_tag_table();
            $blue_tag = new GtkTextTag('colorLine');
            $blue_tag->set_property('background', "#f2e911");
            $tag_table->add($blue_tag);
        } else {

            $fileArray = $this->previousFile['fileArray'];

            $buffer = $this->glade->get_widget('sourcecode')->get_buffer();
            $buffer->remove_all_tags($buffer->get_start_iter(), $buffer->get_end_iter());
            $tag_table = $buffer->get_tag_table();
            $blue_tag = $tag_table->lookup('colorLine');
        }

        $this->glade->get_widget('window1')
            ->set_title($this->steps[$step]['filename']);

        $this->currentFile = $fileArray;

        $position = $buffer->get_iter_at_line($this->steps[$step]['line']-1);

        $buffer->place_cursor($position);

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

    protected function startProgressBar()
    {
        $dialog = new GtkDialog('Work in progress...',
            null, Gtk::DIALOG_MODAL); // create a new dialog
        $top_area = $dialog->vbox;
        $top_area->pack_start(new GtkLabel(
            'Please hold on while processing data...'));
        $this->progressBar = new GtkProgressBar();
        $this->progressBar->set_orientation(Gtk::PROGRESS_LEFT_TO_RIGHT);
        $top_area->pack_start($this->progressBar, 0, 0);
        $dialog->set_has_separator(false);
        $dialog->show_all(); // show the dialog
        $this->dialog = $dialog; // keep a copy of the dialog ID

        $dialog->connect('delete-event',
            array( &$this, "onDeleteEvent")); // note 3

        while (Gtk::events_pending()) {Gtk::main_iteration();}
    }

    // function that is called when user closes the progress bar dialog
    public function onDeleteEvent($widget, $event) {
        $this->dialog->destroy(); // close the dialog
        // any other clean-up that you may want to do
        return true;
    }

    protected function stopProgressBar()
    {
        $this->dialog->destroy(); // yes, all done. close the dialog
    }

    protected function updateProgressBar()
    {
        $this->progressBar->set_fraction($this->currentProgress);
        $this->progressBar->set_text(
            number_format($this->currentProgress*100, 0).'% Complete');

        do {Gtk::main_iteration();} while (Gtk::events_pending());
    }

    protected function processTraceFile ()
    {
        $this->steps = array();

        $handle = @fopen($this->fileName, "r");
        if ($handle) {
            $i = 0;
            $padding = 0;
            $offset = 0;
            $stats = fstat($handle);
            $fileSize = $stats['size'];
            $lastPercent = 0;

            while (($buffer = fgets($handle)) !== false) {
                $offset += strlen($buffer);
                $this->currentProgress = $offset / $fileSize;
                $currentPercent = round($this->currentProgress * 100);
                if ($currentPercent > $lastPercent && $currentPercent % 2 == 0) {
                    $lastPercent = $currentPercent;
                    $this->updateProgressBar();
                }
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
