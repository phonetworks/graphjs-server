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
use PhoNetworksAutogenerated\User;
use Mailgun\Mailgun;
use GraphJS\Crypto;

 /**
 * Takes care of Authentication
 * 
 * @author Emre Sokullu <emre@phonetworks.org>
 */
class AuthenticationController extends AbstractController
{
 
 const PASSWORD_RECOVERY_EXPIRY = 15*60;

    public function signupViaToken(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        $key = getenv("SINGLE_SIGNON_TOKEN_KEY") ? getenv("SINGLE_SIGNON_TOKEN_KEY") : "";
        if(empty($key)) {
            return $this->fail($response, "Single sign-on not allowed");
        }
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'username' => 'required',
            'email' => 'required|email',
            'token' => 'required',
        ]);
        if($validation->fails()) {
            $this->fail($response, "Valid username, email are required.");
            return;
        }
        if(!preg_match("/^[a-zA-Z0-9_]{1,12}$/", $data["username"])) {
            $this->fail($response, "Invalid username");
            return;
        }
        try {
            $username = Crypto::decrypt($data["token"], $key);
        }
        catch(\Exception $e) {
            return $this->fail($response, "Invalid token");
        }
        if($username!=$data["username"]) {
            return $this->fail($response, "Invalid token");
        }
        $password = str_replace(["/","\\"], "", substr(password_hash($username, PASSWORD_BCRYPT, ["salt"=>$key]), -8));
        error_log("sign up password is ".$password);
        $this->actualSignup($request,  $response,  $session,  $kernel, $username, $data["email"], $password);
    }

    /**
     * Sign Up
     * 
     * [username, email, password]
     * 
     * @param Request  $request
     * @param Response $response
     * @param Session  $session
     * @param Kernel   $kernel
     * 
     * @return void
     */
    public function signup(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'username' => 'required',
            'email' => 'required|email',
            'password' => 'required',
        ]);
        if($validation->fails()) {
            $this->fail($response, "Valid username, email and password required.");
            return;
        }
        if(!preg_match("/^[a-zA-Z0-9_]{1,12}$/", $data["username"])) {
            $this->fail($response, "Invalid username");
            return;
        }
        if(!preg_match("/[0-9A-Za-z!@#$%_]{5,15}/", $data["password"])) {
            $this->fail($response, "Invalid password");
            return;
        }
        $this->actualSignup( $request,  $response,  $session,  $kernel, $data["username"], $data["email"], $data["password"]);
    }

    protected function actualSignup(Request $request, Response $response, Session $session, Kernel $kernel, string $username, string $email, string $password)
    {
        //$verificationRequired = $this->isVerificationRequired($kernel);
        $data = $request->getQueryParams();
        $extra_reqs_to_validate = [];
        $reqs = $kernel->graph()->attributes()->toArray();
        //error_log("about to enter custom_fields loop: ".print_r($reqs, true));
        error_log("about to enter custom_fields loop");
        error_log("about to enter custom_fields loop: ".count($reqs));
        error_log("about to enter custom_fields loop: ".print_r(array_keys($reqs), true));
        for($i=1;$i<4;$i++) {
            if(
                isset($reqs["CustomField{$i}Must"])&&isset($reqs["CustomField{$i}"])
                &&$reqs["CustomField{$i}Must"]
                &&!empty($reqs["CustomField{$i}"])
            ) {
                $field = "custom_field{$i}"; // $reqs["CustomField{$i}"];
                $extra_reqs_to_validate[$field] = 'required';
            }
        }
        error_log("out of custom_fields loop");
        $validation = $this->validator->validate($data, $extra_reqs_to_validate);
        if($validation->fails()) {
            return $this->fail($response, "Valid ".addslashes(implode(", ", $extra_reqs_to_validate)). " required.");
        }
        
        $result = $kernel->index()->query(
            "MATCH (n:user) WHERE n.Username= {username} OR n.Email = {email} RETURN n",
            [ 
                "username" => $username,
                "email"    => $email
            ]
        );
        error_log(print_r($result, true));
        $duplicate = (count($result->results()) >= 1);
        if($duplicate) {
            error_log("duplicate!!! ");
            $this->fail($response, "Duplicate user");
            return;
        }

        try {
            $new_user = new User(
                $kernel, $kernel->graph(), $username, $email, $password
            );
        } catch(\Exception $e) {
            $this->fail($response, $e->getMessage());
            return;
        }


        for($i=1;$i<4;$i++) {
            if(isset($reqs["CustomField{$i}"])&&!empty($reqs["CustomField{$i}"])&&isset($data["custom_field{$i}"])&&!empty($data["custom_field{$i}"])) {
                $_ = "setCustomField{$i}";
                $new_user->$_($data["custom_field{$i}"]);
            }
        }

        $moderation = $this->isMembershipModerated($kernel);
        if($moderation)
            $new_user->setPending(true);

        $verification = $this->isVerificationRequired($kernel);
        if($verification) {
            $pin = rand(100000, 999999);
            $new_user->setPendingVerification($pin);
                $mgClient = new Mailgun(getenv("MAILGUN_KEY")); 
                $mgClient->sendMessage(getenv("MAILGUN_DOMAIN"),
                array('from'    => 'GraphJS <postmaster@client.graphjs.com>',
                        'to'      => $data["email"],
                        'subject' => 'Please Verify',
                        'text'    => 'Please enter this 6 digit passcode to verify your email: '.$pin)
                );
        }

        $session->set($request, "id", (string) $new_user->id());
        $this->succeed(
            $response, [
                "id" => (string) $new_user->id(),
                "pending_moderation"=>$moderation,
                "pending_verification"=>$verification
            ]
        );
    }

    /**
     * Log In
     * 
     * [username, password]
     *
     * @param Request  $request
     * @param Response $response
     * @param Session  $session
     * @param Kernel   $kernel
     * 
     * @return void
     */
    public function login(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'username' => 'required',
            'password' => 'required',
        ]);
        if($validation->fails()) {
            $this->fail($response, "Username and password fields are required.");
            return;
        }

        $this->actualLogin($request, $response, $session, $kernel, $data["username"], $data["password"]);

    }

    /**
     * Log In Via Token
     * 
     * [token]
     *
     * @param Request  $request
     * @param Response $response
     * @param Session  $session
     * @param Kernel   $kernel
     * 
     * @return void
     */
    public function loginViaToken(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        $key = getenv("SINGLE_SIGNON_TOKEN_KEY") ? getenv("SINGLE_SIGNON_TOKEN_KEY") : "";
        if(empty($key)) {
            return $this->fail($response, "Single sign-on not allowed");
        }
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'token' => 'required',
        ]);
        if($validation->fails()) {
            $this->fail($response, "Token field is required.");
            return;
        }
        try {
            $username = Crypto::decrypt($data["token"], $key);
        }
        catch(\Exception $e) {
            return $this->fail($response, "Invalid token");
        }
        $password = str_replace(["/","\\"], "", substr(password_hash($username, PASSWORD_BCRYPT, ["salt"=>$key]), -8)); // substr(password_hash($username, PASSWORD_BCRYPT, ["salt"=>$key]), -8);
        error_log("username is: ".$username."\npassword is: ".$password);
        
        $this->actualLogin($request, $response, $session, $kernel, $username, $password);
        
    }

    protected function actualLoginViaEmail($kernel, string $email, string $password): ?array
    {
        $result = $kernel->index()->query(
            "MATCH (n:user {Email: {email}, Password: {password}}) RETURN n",
            [ 
                "email" => $email,
                "password" => md5($password)
            ]
        );
        $success = (count($result->results()) >= 1);
        if(!$success) {
            return null;
        }
        return $result->results()[0];
    }

    protected function actualLogin(Request $request, Response $response, Session $session, Kernel $kernel, string $username, string $password): void
    {
        $result = $kernel->index()->query(
            "MATCH (n:user {Username: {username}, Password: {password}}) RETURN n",
            [ 
                "username" => $username,
                "password" => md5($password)
            ]
        );
        error_log(print_r($result, true));
        $success = (count($result->results()) >= 1);
        if(!$success) {
            error_log("try with email!!! ");
            $user = $this->actualLoginViaEmail($kernel, $username, $password);
            if(is_null($user)) {
                error_log("failing!!! ");
                $this->fail($response, "Information don't match records");
                return;
            }
        }
        else {
            error_log("is a  success");
            $user = $result->results()[0];
        }
        
        error_log(print_r($user));
        error_log(intval($this->isMembershipModerated($kernel)));
        error_log("Done");

        if($this->isMembershipModerated($kernel) && $user["n.Pending"]) {
            $this->fail($response, "Pending membership");
            return;
        }

        if($this->isVerificationRequired($kernel) && $user["n.PendingVerification"]) {
            $this->fail($response, "You have not verified your email yet");
            return;
        }

        $session->set($request, "id", $user["n.udid"]);
        $this->succeed(
            $response, [
                "id" => $user["n.udid"],
                "pending" => $user["n.Pending"]
            ]
        );

        error_log("is a  success");
    }

    /**
     * Log Out
     *
     * @param  Request  $request
     * @param  Response $response
     * @param  Session  $session
     * @return void
     */
    public function logout(Request $request, Response $response, Session $session) 
    {
        $session->set($request, "id", null);
        $this->succeed($response);
    }

    /**
     * Who Am I?
     * 
     * @param  Request  $request
     * @param  Response $response
     * @param  Session  $session
     * @return void
     */
    public function whoami(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        if(!is_null($id = $this->dependOnSession(...\func_get_args()))) {
            try {
                $i = $kernel->gs()->node($id);
            }
            catch(\Exception $e) {
                return $this->fail($response, "Invalid user");
            }
            $this->succeed($response, [
                    "id" => $id, 
                    "admin" => (bool) ($id==$kernel->founder()->id()->toString()),
                    "username" => (string) $i->getUsername(),
                    "editor" => ( 
                        (($id==$kernel->founder()->id()->toString())) 
                        || 
                        (isset($i->attributes()->IsEditor) && (bool) $i->getIsEditor())
                    ),
                    "pending" => (
                        (isset($i->attributes()->Pending) && (bool) $i->getPending())
                    )
                ]
            );
        }
        else {
            $this->fail($response, "You are not logged in");
        }
    }

    public function reset(Request $request, Response $response, Kernel $kernel)
    {
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'email' => 'required|email',
        ]);
        if($validation->fails()) {
            $this->fail($response, "Valid email required.");
            return;
        }


        $result = $kernel->index()->query(
            "MATCH (n:user {Email: {email}}) RETURN n",
            [ 
                "email" => $data["email"]
            ]
        );
        $success = (count($result->results()) >= 1);
        if(!$success) {
            $this->succeed($response); // because we don't want to let them kniow our userbase
            return;
        }


        // check if email exists ?
        $pin = mt_rand(100000, 999999);
        if($this->isRedisPasswordReminder()) {
            $kernel->database()->set("password-reminder-".md5($data["email"]), $pin);
            $kernel->database()->expire("password-reminder-".md5($data["email"]), self::PASSWORD_RECOVERY_EXPIRY);
        }
        else{
            file_put_contents(getenv("PASSWORD_REMINDER").md5($data["email"]), "{$pin}:".time()."\n", LOCK_EX);
        }
        $mgClient = new Mailgun(getenv("MAILGUN_KEY")); 
        $mgClient->sendMessage(getenv("MAILGUN_DOMAIN"),
        array('from'    => 'GraphJS <postmaster@client.graphjs.com>',
                'to'      => $data["email"],
                'subject' => 'Password Reminder',
                'text'    => 'You may enter this 6 digit passcode: '.$pin)
        );
        $this->succeed($response);
    }
 
 protected function isRedisPasswordReminder(): bool
 {
      $redis_password_reminder = getenv("PASSWORD_REMINDER_ON_REDIS");
      error_log("password reminder is ".$redis_password_reminder);
      return($redis_password_reminder===1||$redis_password_reminder==="1"||$redis_password_reminder==="on");
 }
 
 public function verifyEmailCode(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'id'   => 'required',
            'code' => 'required'
        ]);
        if($validation->fails()
        ||!preg_match("/^[0-9]{6}$/", $data["code"])
        ||!preg_match("/^[0-9a-fA-F]{32}$/", $data["id"])
        ) {
            $this->fail($response, "Valid code, ID are required.");
            return;
        }
        $id =(string) $data["id"];
        try {
            $i = $kernel->gs()->node($id);
        }
        catch(\Exception $e) {
            return $this->fail($response, "Invalid user");
        }

        $code_expected = (int) $i->getPendingVerification();
        if($code_expected==0)
            return $this->fail($response, "Invalid code");

        $data["code"] = (int) $data["code"];
        if($code_expected!=$data["code"]) {
            return $this->fail($response, "Invalid code");
        }

        $i->setPendingVerification(0);

        $data["id"] = strtolower($data["id"]);
        $session->set($request, "id", $data["id"]);
        return $this->succeed($response);
    }

    public function verify(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'email' => 'required|email',
            'code' => 'required',
        ]);
        if($validation->fails()||!preg_match("/^[0-9]{6}$/", $data["code"])) {
            $this->fail($response, "Valid email and code required.");
            return;
        }
        $pins = explode(":", trim(file_get_contents(getenv("PASSWORD_REMINDER").md5($data["email"]))));
        if($this->isRedisPasswordReminder()) {
            $pins = [];
            $pins[0] = $kernel->database()->get("password-reminder-".md5($data["email"]));
        }
        else{
            $pins = explode(":", trim(file_get_contents(getenv("PASSWORD_REMINDER").md5($data["email"]))));
        }
        //error_log(print_r($pins, true));
        if($pins[0]==$data["code"]) {
            //if((int) $pins[1]<time()-7*60) {
            if(!$this->isRedisPasswordReminder() && (int) $pins[1]<time()-self::PASSWORD_RECOVERY_EXPIRY) {
                $this->fail($response, "Expired.");
                return;
            }
 
         
         $result = $kernel->index()->query(
            "MATCH (n:user {Email: {email}}) RETURN n",
            [ 
                "email" => $data["email"]
            ]
        );
        $success = (count($result->results()) >= 1);
        if(!$success) {
            $this->fail($response, "This user is not registered");
            return;
        }
        $user = $result->results()[0];
        $session->set($request, "id", $user["n.udid"]);
         
         
            return $this->succeed($response);
        }
        $this->fail($response, "Code does not match.");
    }

}
