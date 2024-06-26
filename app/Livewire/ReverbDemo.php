<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Events\SendDemoMessage;

class ReverbDemo extends Component
{

    public string $message;

    public array $chats = [];


    public function render()
    {
        return view('livewire.reverb-demo');
    }


    public function sendMessage():void
    {
        event(new SendDemoMessage($this->message));
        $this->message = '';
    }

    #[On('echo:demo-channel,SendDemoMessage')]
    public function handleDemoMessage($message):void
    {
        // dd($message);
        $chats = array_merge($this->chats,$message);
        $this->chats[] = $chats;

        // dd($this->chats);

    }
}
