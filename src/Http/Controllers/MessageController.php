<?php

namespace Myckhel\ChatSystem\Http\Controllers;

use Myckhel\ChatSystem\Models\Message;
use Illuminate\Http\Request;
use Myckhel\ChatSystem\Http\Requests\PaginableRequest;
use Illuminate\Validation\Rule;
use Myckhel\ChatSystem\Events\Message\Created;
use Myckhel\ChatSystem\Notifications\Message\Created as CreatedMessage;

class MessageController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(PaginableRequest $request)
    {
      $request->validate([
        'orderBy'            => '',
        'search'             => 'min:3',
        'order'              => 'in:asc,desc',
        'pageSize'           => 'int',
        'conversation_id'    => 'int|nullable',
        'other_user_id'      => 'int',
        'system'             => 'boolean',
        'reply_id'           => 'int',
        'reply_type'         => [
          Rule::requiredIf(fn () => $request->reply_id),
          "in:".Message::class,
        ],
      ]);

      $user     = $request->user();
      $pageSize = $request->pageSize;
      $order    = $request->order;
      $orderBy  = $request->orderBy;
      $reply_type  = $request->reply_type;
      $reply_id = $request->reply_id;
      $system   = $request->system;
      $reply    = [];
      $with     = [
        'reply',
        'trashed' => fn ($q) => $q->withTrashed($user),
        'sender',
      ];

      if ($reply_id) {
        $reply['reply_id']    = (int)$reply_id;
      }
      if ($reply_type) {
        $reply['reply_type']  = $reply_type;
      }


      $messages = $user->messages($request->conversation_id, $request->other_user_id, $reply ?? null)
      // ->withUrls(['image', 'videos'])
      ->whereConversationWasntDeleted($user)
      ->with($with)->latest()
      ->paginate($request->pageSize);

      // $messages->withUrls(['image', 'videos']);

      if ($system && $messages->currentPage() == $messages->lastPage()) {
        $msg = $messages->first();
        $conversation_id = $msg ? $msg->conversation_id : $request->conversation_id ?? $user->conversations()->latest()->first()->id ?? null;
        $systemSafe = new Message([
          'conversation_id' => $conversation_id,
          'message' => trans('msg.chat.system.safety'),
        ]);
        $systemSafe->setAppends(['system']);
        $systemSafe->id = 1;

        $systemDesc = new Message([
          'conversation_id' => $conversation_id,
          'message' => trans('msg.chat.system.msg_desc'),
        ]);
        $systemDesc->setAppends(['system']);
        $systemDesc->id = 2;

        $messages->appendSystemMessage($systemDesc, $systemSafe);
      }

      return $messages;
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
      $request->validate([
        'conversation_id' => 'int',
        'other_user_id'   => ['int', Rule::requiredIf(fn () => !$request->conversation_id)],
        'message'         => '',
        'reply_id'        => 'int',
        'reply_type'      => [
          Rule::requiredIf(fn () => $request->reply_id),
          "in:".Message::class,
        ],
        // 'videos.*'        => 'mimetypes:video/webm,video/mp,video/mp4,video/quicktime|max:40000',
      ]);
      $user       = $request->user();
      $image      = $request->image;
      $videos     = $request->videos;
      $token      = $request->token;
      $otherUser  = $request->other_user_id ? $user->findOrFail($request->other_user_id) : null;

      $conversation = $user->conversations($request->conversation_id, $request->other_user_id)
      ->first();

      if (!$conversation){
        $conversation = $user->conversations()->create([
            'user_id' => $user->id,
        ]);
        $conversation->participants()->create([
            'user_id' => $otherUser->id,
        ]);
      }

      $message = $conversation->messages()
      ->when(
        $token,
        fn ($q) => $q->whereHas('metas', fn ($q) =>
          $q->whereMetableType(Message::class)
          ->whereName('token')->whereValue($token)
        ),
        fn ($q) => $q->whereNull('id')
      )
      ->firstOrCreate([],
        [
          'reply_id'        => $request->reply_id,
          'reply_type'      => $request->reply_type,
          'user_id'         => $user->id,
          'message'         => $request->message,
        ]
      );
      $message->loadMorph('reply', [
          Message::class => ['reply'],
      ]);

      if ($message->wasRecentlyCreated) {
        if ($token) {
          $meta = ['name' => 'token', 'value' => $token];
          $message->addMeta($meta, $meta);
        }

        // $message->saveImage($image, 'image');
        // $message->saveVideo($videos, 'videos');

        broadcast(new Created(
          $message,
          $message->image,
          $message->videos,
        ));

        $token && $message->metas->keyValue();
        // $otherUser->notify(new CreatedMessage($message, $user));
      }

      return $message;
    }

    /**
     * Display the specified resource.
     *
     * @param  \Myckhel\ChatSystem\Models\Message  $message
     * @return \Illuminate\Http\Response
     */
    public function show(Message $message)
    {
      return $message;
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \Myckhel\ChatSystem\Models\Message  $message
     * @return \Illuminate\Http\Response
     */
    public function edit(Message $message)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Myckhel\ChatSystem\Models\Message  $message
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Message $message)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \Myckhel\ChatSystem\Models\Message  $message
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, Message $message)
    {
      // $this->authorize('delete', $message);
      $request->validate([
        'everyone' => 'bool'
			]);

      $user     = $request->user();
			$everyone = $request->everyone;

			if($message->participantsHasDeleted()){
  			return ['status' => $message->forceDelete()];
			}
			return ['status' => $message->makeDelete($user, $everyone)];
    }

    /**
     * Remove the multiple messages from storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function delete(Request $request)
    {
      $request->validate([
        'everyone'    => 'bool',
        'messages'    => 'required|array',
        'messages.*'  => 'int',
      ]);

      $user     = $request->user();
      $everyone = $request->everyone;
      $messages = $request->messages;

      $messages = $user->messages()->whereNotTrashed($user->id)->with(['trashed'])->whereIn('id', $messages)->paginate();

      return $messages->makeDelete($user, $everyone);
    }
}