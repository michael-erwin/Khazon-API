<?php

namespace App\Exceptions;

class APIError
{
    protected $response = [
        "error" => [
            "code" => "",
            "message" => "",
        ]
    ];

    private function setResponse($code, $data, $message)
    {
        $this->response['error']['code'] = $code;
        $this->response['error']['message'] = $message;
        if($data) $this->response['error']['data'] = $data;
    }

    public function badRequest($data=null, $message="Bad request.")
    {
        $this->setResponse('BAD_REQUEST', $data, $message);
        return response()->json($this->response,400);
    }

    public function forbidden($data=null, $message="Forbidden.")
    {
        $this->setResponse('FORBIDDEN', $data, $message);
        return response()->json($this->response,403);
    }

    public function invalidInput($data=null, $message="Invalid input.")
    {
        $this->setResponse('INVALID_INPUT', $data, $message);
        return response()->json($this->response,422);
    }

    public function locked($data=null, $message="Resource is locked.")
    {
        $this->setResponse('LOCKED', $data, $message);
        return response()->json($this->response,423);
    }

    public function notFound($data=null, $message="Not found.")
    {
        $this->setResponse('NOT_FOUND', $data, $message);
        return response()->json($this->response,404);
    }

    public function unauthorized($data=null, $message="Unauthorized.")
    {
        $this->setResponse('UNAUTHORIZED', $data, $message);
        return response()->json($this->response,401);
    }

    public function serverError($data=null, $message="Internal server error.")
    {
        $this->setResponse('SERVER_ERROR', $data, $message);
        return response()->json($this->response,500);
    }
}