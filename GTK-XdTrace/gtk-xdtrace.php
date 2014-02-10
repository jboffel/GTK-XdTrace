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

    function __construct ($glade)
    {
        $this->glade = $glade;
    }

    public function openFile ($obj)
    {
        $this->fileName = $obj->get_filename();
        
        $this->processTraceFile();
        
        $this->showFileStepList();
        
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
    
    // public function followScroll ()
    // {
    // $adj = $this->glade->get_widget('scrolledwindow1')
    // ->get_vadjustment();
    
    // $min = $adj->lower;
    // $max = $adj->upper;
    
    // print_r($min);
    // echo "\n";
    // print_r($max);
    // echo "\n";
    // print_r($adj->page_size);
    // echo "\n";
    
    // $percent = $this->steps[$this->pointer]['line'] /
    // count($this->currentFile) * 100;
    
    // print_r($percent);
    // echo "\n";
    
    // $adjValue = ($max * $percent) / 100 - $adj->page_size / 2;
    
    // print_r($adjValue);
    // echo "\n";
    // print_r($adj->get_value());
    // echo "\n";
    
    // if ($adjValue >= 0)
    // $adj->set_value($adjValue);
    
    // $scroll = $this->glade->get_widget('scrolledwindow1')
    // ->set_vadjustment($adj);
    
    // }
    protected function showStoryStep ($step)
    {
        if ($step < 0 || $step > count($this->steps))
            return;
        
        $buffer = $this->glade->get_widget('sourcecode')
            ->get_buffer();
        
        $buffer->set_text($file = file_get_contents($this->steps[$step]['filename']));
        
        $this->glade->get_widget('window1')
            ->set_title($this->steps[$step]['filename']);
        
        $this->currentFile = $fileArray = file($this->steps[$step]['filename']);
        
        $position = $buffer->get_iter_at_line($this->steps[$step]['line']);
        
        $buffer->place_cursor($position);
        
        $tag_table = $buffer->get_tag_table();
        $blue_tag = new GtkTextTag();
        $blue_tag->set_property('background', "#ff00ff");
        $tag_table->add($blue_tag);
        
        $offsetStart = 0;
        
        for ($i = 0; $i < $this->steps[$step]['line'] - 1; $i ++) {
            $offsetStart += strlen($fileArray[$i]);
        }
        
        $start = $buffer->get_iter_at_offset($offsetStart);
        $end = $buffer->get_iter_at_offset($offsetStart + strlen($fileArray[$i]));
        
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
        
        $buffer->set_text($this->steps[$step]['fonction']);
        
        $buffer = $this->glade->get_widget('returncurrentinstruction')
            ->get_buffer();
        
        $buffer->set_text($this->steps[$step]['returnValue']);
        
        $this->glade->get_widget('totalsteps')
            ->set_text($step . '/' . count($this->steps));
        
        // $this->followScroll();
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
//         $this->retValue = array();
        
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
                        'fonction' => $steps[5], 
                        'filename' => $steps[6], 
                        'line' => $steps[7], 
                        'line2' => $i, 
                        'returnValue' => NULL
                    );
                    $this->globalFileNameListInOrder[] = $steps[6];
                }
                $res = preg_match('/^(\s+)>=>\s(.*)$/', $buffer, $retValue);
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
                    $this->steps[$w]['returnValue'] = $retValue[2];
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