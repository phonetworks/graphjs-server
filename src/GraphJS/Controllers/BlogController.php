<?php

/*
 * This file is part of the Pho package.
 *
 * (c) Emre Sokullu <emre@phonetworks.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

 namespace GraphJS\Controllers;

 use CapMousse\ReactRestify\Http\Request;
 use CapMousse\ReactRestify\Http\Response;
 use CapMousse\ReactRestify\Http\Session;
 use Pho\Kernel\Kernel;
 use Pho\Kernel\Foundation\AbstractActor;
 use PhoNetworksAutogenerated\User;
 use PhoNetworksAutogenerated\Page;
use PhoNetworksAutogenerated\Blog;
use PhoNetworksAutogenerated\UserOut\Comment;
use Pho\Lib\Graph\ID;
 

  /**
 * Takes care of Blog functionality
 * 
 * @author Emre Sokullu <emre@phonetworks.org>
 */
class BlogController extends AbstractController
{
    // postBlog
    // > $user->postBlog("title", "content");
    // editBlog
    // dleeteBlog

    public function fetchAll(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        $data = $request->getQueryParams();
        $blogs = $pinned = [];
        $everything = $kernel->graph()->members();
        $is_moderated = $kernel->graph()->getCommentsModerated();
        foreach($everything as $thing) {

            if($thing instanceof Blog) {
                try{
                error_log("blog id: ".$thing->id()->toString());
                $publish_time =  intval($thing->getPublishTime());
                $comments = $is_moderated ?
                    array_filter($thing->getComments(), function(Comment $comm) {
                        return $comm->getPending() !== true;
                    })
                    : $thing->getComments();
                $comment_count = (string) count($comments);
                $author = $thing->getAuthor();
                $title = $thing->getTitle();
                $summary = $thing->getContent();
                error_log("Author(s) as follows: ".gettype($author));
                //error_log(print_r($author, true));
                if(
                    !($author instanceof User) ||
                    ($title=="Unnamed"&&$summary=="Dummy content...")
                ) {
                    continue;
                    /*
                    if(!is_array($author)) {
                        continue;
                    }
                    $author = $author[0];
                    */
                }
                //eval(\Psy\sh());
                $item = [
                    "id" => (string) $thing->id(),
                    "title" => $title,
                    "summary" => $summary,
                    "author" => [
                        "id" => (string) $thing->getAuthor()->id(),
                       "username" => (string) $thing->getAuthor()->getUsername()
                    ],
                    "start_time" => (string) $thing->getCreateTime(),
                    "is_draft" => ($publish_time == 0),
                    "last_edit" => (string) $thing->getLastEditTime(),
                    "publish_time" => (string) $publish_time,
                    "comment_count" => $comment_count
                ];

                $is_pinned = $this->getIsPinned();
                if($is_pinned) {
                    $pinned[] = $item;
                    continue;
                }
                $blogs[] = $item;
            }
            catch(\Exception $e) {
                // missing edge or something
                
            }
            }
        }

        // https://stackoverflow.com/questions/1597736/how-to-sort-an-array-of-associative-arrays-by-value-of-a-given-key-in-php
        if((isset($data["order"])&&$data["order"]=="asc") ) {
            usort($pinned, function ($item1, $item2) {
                return $item1['publish_time'] <=> $item2['publish_time'];
            });
            usort($blogs, function ($item1, $item2) {
                return $item1['publish_time'] <=> $item2['publish_time'];
            });
        }
        else {
            usort($pinned, function ($item1, $item2) {
                return $item2['publish_time'] <=> $item1['publish_time'];
            });
            usort($blogs, function ($item1, $item2) {
                return $item2['publish_time'] <=> $item1['publish_time'];
            });
        }

        $blogs = array_merge($pinned, $blogs);
        $blogs_count = count($blogs);
        $blogs = $this->paginate($blogs, $data);
        
        $this->succeed(
            $response, [
                "blogs" => $blogs ,
                "total" => $blogs_count
            ]
        );
    }

    public function fetch(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'id' => 'required',
        ]);
        if($validation->fails()) {
            return $this->fail($response, "Title (up to 255 chars) and Content are required.");
        }
        try {
            $blog = $kernel->gs()->node($data["id"]);
        }
        catch(\Exception $e) {
            return $this->fail($response, "No such Blog Post");
        }
        if(!$blog instanceof Blog) {
            return $this->fail($response, "Given id is not a blog post");
        }
        $publish_time =  intval($blog->getPublishTime());
        $this->succeed(
            $response, [
                "blog" => [
                    "id" => (string) $blog->id(),
                    "title" => $blog->getTitle(),
                    "summary" => $blog->getContent(),
                    "author" => [
                        "id" => (string) $blog->getAuthor()->id(),
                       "username" => (string) $blog->getAuthor()->getUsername()
                    ],
                    "start_time" => (string) $blog->getCreateTime(),
                    "is_draft" => ($publish_time == 0),
                    "last_edit" => (string) $blog->getLastEditTime(),
                    "publish_time" => (string) $publish_time
                ]
            ]
        );
    }

    public function pinOp(bool $op = true, Request $request, Response $response, Session $session, Kernel $kernel)
    {
        if(is_null($id = $this->dependOnSession(...\func_get_args()))) {
            return;
        }
        if($id!=$kernel->founder()->id()->toString()) {
            $this->fail($response, "Only admins can operate this command.");
            return;
        }
        $validation = $this->validator->validate($data, [
            'id' => 'required'
        ]);
        if($validation->fails()) {
            $this->fail($response, "Content ID required");
            return;
        }
        try {
            $blog = $kernel->gs()->node($data["id"]);
        }
        catch(\Exception $e) {
            return $this->fail($response, "No such Blog Post");
        }
        if(!$blog instanceof Blog) {
            return $this->fail($response, "Given id is not a blog post");
        }
        $blog->setIsPinned($op);
        $this->succeed($response);
    }

    public function pin(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        $this->pinOp(true, ...func_get_args());
    }

    public function unpin(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        $this->pinOp(false, ...func_get_args());
    }


    public function post(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        if(is_null($id = $this->dependOnSession(...\func_get_args()))) {
            return;
        }
        $data = $request->getQueryParams();
        $_data = $request->getData();
        if(isset($_data["title"]))
            $data["title"]  = $_data["title"];
        if(isset($_data["content"]))
            $data["content"]  = $_data["content"];
        $validation = $this->validator->validate($data, [
            'title' => 'required|max:255',
            'content' => 'required',
        ]);
        if($validation->fails()) {
            $this->fail($response, "Title (up to 255 chars) and Content are required.");
            return;
        }
        $i = $kernel->gs()->node($id);
        $can_edit = $this->canEdit($kernel, $i);
        if(!$can_edit) {
            return $this->fail($response, "No privileges for blog posts");
        }
        $blog = $i->postBlog($data["title"], $data["content"]);
        $this->succeed(
            $response, [
                "id" => (string) $blog->id()
            ]
        );
    }


    public function edit(Request $request, Response $response, Session $session, Kernel $kernel) 
    {
     if(is_null($id = $this->dependOnSession(...\func_get_args()))) {
            return;
        }
     $data = $request->getQueryParams();
     $_data = $request->getData();
        if(isset($_data["title"]))
            $data["title"]  = $_data["title"];
        if(isset($_data["content"]))
            $data["content"]  = $_data["content"];
        if(isset($_data["id"]))
            $data["id"]  = $_data["id"];
        $validation = $this->validator->validate($data, [
            'id' => 'required',
            'title'=>'required',
            'content' => 'required',
        ]);
        if($validation->fails()) {
            $this->fail($response, "ID, Title and Content are required.");
            return;
        }
        $i = $kernel->gs()->node($id);
        $can_edit = $this->canEdit($kernel, $i);
        if(!$can_edit) {
            return $this->fail($response, "No privileges for blog posts");
        }
        try {
            $entity = $kernel->gs()->entity($data["id"]);
        }
        catch(\Exception $e) 
        {
            return $this->fail($response, "Invalid ID");
        }
        if(!$entity instanceof Blog) {
            $this->fail($response, "Given ID is not a Blog.");
            return;
        }
        try {
        $i->edit($entity)->setTitle($data["title"]);
        $i->edit($entity)->setContent($data["content"]);
        $i->edit($entity)->setLastEditTime(time());
        }
     catch(\Exception $e) {
        $this->fail($response, $e->getMessage());
            return;
     }
     $this->succeed($response);
    }


    public function delete(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        if(is_null($id = $this->dependOnSession(...\func_get_args()))) {
            return;
        }
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'id' => 'required',
        ]);
        if($validation->fails()) {
            $this->fail($response, "ID is required.");
            return;
        }
        try {
            $i = $kernel->gs()->node($id);
        }
        catch (\Exception $e) {
            return $this->fail($response, "Invalid ID");
        }
            try {
            $blog = $kernel->gs()->node($data["id"]);
            }
            catch(\Exception $e) {
                return $this->fail($response, "Invalid ID");
            }
            if(!$blog instanceof Blog) {
                return $this->fail($response, "Invalid ID");
            }
            // check author
            if(
                !$i->id()->equals($kernel->founder()->id()) 
                &&
                !$blog->getAuthor()->id()->equals($i->id())
            ) {
                return $this->fail($response, "No privileges to delete this content");
            }
            try {
              $blog->destroy(); 
            }
            catch(\Pho\Framework\Exceptions\InvalidParticleMethodException $e) {
                error_log($e->getMessage());
                return $this->fail($response, "Problem destroying the node");
            }
            return $this->succeed($response);
        
    }


    protected function canEdit(Kernel $kernel, AbstractActor $actor)
    {
        return (
            getenv('INSTALLATION_TYPE') === 'groupsv2'  ||
            $kernel->founder()->id()->equals($actor->id()) ||
            isset($actor->attributes()->IsEditor) && (bool) $actor->getIsEditor()
        );
    }


    public function publish(Request $request, Response $response, Session $session, Kernel $kernel)
    {

     if(is_null($id = $this->dependOnSession(...\func_get_args()))) {
        return;
    }
 $data = $request->getQueryParams();
    $validation = $this->validator->validate($data, [
        'id' => 'required',
    ]);
    if($validation->fails()) {
        $this->fail($response, "ID is required.");
        return;
    }
    $i = $kernel->gs()->node($id);
    try {
    $entity = $kernel->gs()->entity($data["id"]);
    }
    catch(\Exception $e) 
    {
        return $this->fail($response, "Invalid ID");
    }
    if(!$entity instanceof Blog) {
        $this->fail($response, "Given ID is not a Blog.");
        return;
    }
    $can_edit = $this->canEdit($kernel, $i);
        if(!$can_edit) {
            return $this->fail($response, "No privileges for blog posts");
        }
    try {
    $i->edit($entity)->setPublishTime(time());
    }
 catch(\Exception $e) {
    $this->fail($response, $e->getMessage());
        return;
 }
 $this->succeed($response);
    }

    public function unpublish(Request $request, Response $response, Session $session, Kernel $kernel)
    {

     if(is_null($id = $this->dependOnSession(...\func_get_args()))) {
        return;
    }
 $data = $request->getQueryParams();
    $validation = $this->validator->validate($data, [
        'id' => 'required'
    ]);
    if($validation->fails()) {
        $this->fail($response, "ID is required.");
        return;
    }
    $i = $kernel->gs()->node($id);
    try {
    $entity = $kernel->gs()->entity($data["id"]);
    }
    catch(\Exception $e) 
    {
        return $this->fail($response, "Invalid ID");
    }
    $can_edit = $this->canEdit($kernel, $i);
        if(!$can_edit) {
            return $this->fail($response, "No privileges for blog posts");
        }
    if(!$entity instanceof Blog) {
        $this->fail($response, "Given ID is not a Blog.");
        return;
    }
    try {
        $i->edit($entity)->setPublishTime(0);
    }
 catch(\Exception $e) {
    $this->fail($response, $e->getMessage());
        return;
 }
 $this->succeed($response);
    }

}
