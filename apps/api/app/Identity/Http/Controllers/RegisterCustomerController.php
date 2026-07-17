<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\RegisterCustomer;
use App\Identity\Data\CustomerData;
use App\Identity\Data\RegisterCustomerData;

class RegisterCustomerController
{
    public function __invoke(RegisterCustomerData $data, RegisterCustomer $registerCustomer): CustomerData
    {
        return $registerCustomer($data);
    }
}
