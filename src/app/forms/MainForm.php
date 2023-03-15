<?php
namespace app\forms;

use std, gui, framework, app, net, php\lang\Thread, php\lang\System, php\lib\str, php\lib\fs, php\time\Time;

// listView - Your Files
// listViewAlt - Other Files

class MainForm extends AbstractForm
{
    /**
     * @event toggleButton.click-Left 
     */
    function doToggleButtonClickLeft(UXMouseEvent $e = null)
    {    
        if ($this->toggleButton->selected) {
            $this->toggleButton->text = 'My Files';
            $this->button->text = 'Add';
            $this->listView->show();
            $this->listViewAlt->hide();
            $this->edit->hide();
        } else {
            $this->toggleButton->text = 'Network Files';
            $this->button->text = 'Scan';
            $this->listViewAlt->show();
            $this->edit->show();
            $this->listView->hide();
        }
    }

    /**
     * @event button.click-Left 
     */
    function doButtonClickLeft(UXMouseEvent $e = null)
    {    
        if($this->toggleButton->selected){
            $this->addNewFile();
        } else {
            $this->scan();
        }
    }

    /**
     * @event listView.click-Right 
     */
    function doListViewClickRight(UXMouseEvent $e = null)
    {    
        if($this->listView->selectedIndex > 0){
            $this->listView->items->remove($this->listView->selectedItem);
            $this->updateFilesCount('listView');
        }
    }

    /**
     * @event listViewAlt.click-Right 
     */
    function doListViewAltClickRight(UXMouseEvent $e = null)
    {
        if($this->listViewAlt->selectedIndex > 0){
            $i = 0;
            for(; $i < str::length($this->listViewAlt->selectedItem); $i++)
                if($this->listViewAlt->selectedItem[$i] === '\\') break;
            $ip = substr($this->listViewAlt->selectedItem, 0, $i);
            $file = substr($this->listViewAlt->selectedItem, $i + 1);
            $this->download($ip, $file);
        }
    }
    
    private function addNewFile(){
        $file = $this->fileChooser->execute();
        if($file) $this->listView->items->add($file);
        $this->updateFilesCount('listView');
    }
    
    private function updateFilesCount($listView){
        if(is_array($listView)){
            foreach($listView as $l)
                $this->updateFilesCount($l);
            return;
        }
        $this->{$listView}->items[0] = '-- ' . (max(0, count($this->{$listView}->items) - 1)) . ' Files --';
    }
    
    private function scan_stream($ip){
        (new Thread(function() use ($ip){
            try {
                $client = new Socket;
                $client->connect($ip, 35796);
                $this->client = $client;
                $client->getOutput()->write('/?get_files|\\');
                $message = $client->getInput()->read(65535);
                uiLater(function () use ($message, $ip, $client){
                    if(str::length($message)){
                        if(str::pos($message, '|') !== -1)
                            foreach(str::split($message, '|') as $file)
                                $this->listViewAlt->items->add($ip . '\\' .base64_decode($file));
                        else
                            $this->listViewAlt->items->add($ip . '\\' . base64_decode($message));
                    }
                    $this->updateFilesCount('listViewAlt');
                    if($client->isConnected())
                        $client->close();
                    System::gc();
                });
            } catch(SocketException $e){}
        }))->start();
    }
    
    private function scan(){
        for($i = 1; $i < count($this->listViewAlt->items); )
            $this->listViewAlt->items->removeByIndex($i);
        $splitted = str::split($this->edit->text, '|');
        if(count($splitted) === 2){
            $abcd = str::split($splitted[0], '.');
            if(count($abcd) === 4){
                $a = $abcd[0]; $b = $abcd[1]; $c = $abcd[2]; $d = $abcd[3];
                $count = intval($splitted[1]);
                while($count-- > 0){
                    $ip = $a . '.' . $b . '.' . $c . '.' . $d++;
                    $this->scan_stream($ip);
                    if($d > 255){$c++; $d = 0;
                    if($c > 255){$b++; $c = 0;
                    if($b > 255){$a++; $b = 0;
                    }}}
                }
            }
        }
    }

    private function download($ip, $file){
        (new Thread(function () use ($ip, $file){
            $client = new Socket;
            $socket = $client->connect($ip, 35796);
            $client->getOutput()->write($file);
            for($i = str::length($file) - 1; $i >= 0; $i--){
                if($file[$i] === '\\' || $file[$i] === '/'){
                    $filename = substr($file, $i + 1);
                    break;
                }
            }
            if(!isset($filename))
                $filename = $file;
            unlink($filename);
            $f = fopen($filename, 'a');
            $millis = Time::millis();
            while ($client->isConnected()){
                $message = $client->getInput()->read(65535);
                if(Time::millis() - $millis > 2000){
                    fwrite($f, $message);
                    break;
                } elseif(str::length($message)){
                    $millis = Time::millis();
                    fwrite($f, $message);
                }
            }
            fclose($f);
            if($client->isConnected())
                $client->close();
            System::gc();
        }))->start();
    }
    
    private function sendFile(string $file, $client, &$clients_count){
        (new Thread(function() use ($file, $client, &$clients_count){
            $output = $client->getOutput();
            $fsize = fs::size($file);
            $pos = 0;
            $bufsize = 65535;
            $f = fopen($file, 'r');
            while($pos < $fsize){
                $output->write(fread($f, $bufsize));
                usleep(10000);
                $pos += $bufsize;
            }
            fclose($f);
            if($client->isConnected())
                $client->close();
            $clients_count--;
            System::gc();
        }))->start();
    }

    /**
     * @event show 
     */
    function doShow(UXEvent $e = null){
        $this->updateFilesCount(['listViewAlt', 'listView']);
        (new Thread(function ()
        {
            $serv = new ServerSocket(35796);
            $clients_count = 0;
            while ($client = $serv->accept()){
                if($clients_count >= 10){
                    if($client->isConnected())
                        $client->close();
                    System::gc();
                    usleep(100000);
                    continue;
                }
                if($client->isClosed()) continue;
                $clients_count++;
                (new Thread(function () use ($client, &$clients_count){
                    try {
                        if($client->isConnected()){
                            $message = $client->getinput()->read(2048);
                            uiLater(function() use ($message, $client, &$clients_count) {
                                if($message === '/?get_files|\\'){
                                    $client->getOutput()->write(join("|",array_map(function($str){return base64_encode($str);},array_slice($this->listView->items->toArray(),1))));
                                    $client->close();
                                    $clients_count--;
                                } else {
                                    for($i = 1; $i < count($this->listView->items); $i++){
                                        if((string) $this->listView->items[$i] === $message){
                                           $this->sendFile($message, $client, $clients_count);
                                           return;
                                        }
                                    }
                                    $client->close();
                                    $clients_count--;
                                }
                            });
                       }
                    } catch(SocketException $e){$clients_count--;}
                }))->start();   
            }
            
        }))->start();
    }

    /**
     * @event close 
     */
    function doClose(UXWindowEvent $e = null)
    {    
        exit();
    }
}
