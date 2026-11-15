<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Customer\Entities\Customer;
use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\CustomerModel;
use Illuminate\Support\Facades\Auth;

class CustomerRepositoryEloquent implements CustomerRepositoryInterface
{
    public function save(Customer $customer): void
    {
        CustomerModel::create([
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'document' => $customer->document,
            'created_user_id' => Auth::id(),
            'updated_user_id' => Auth::id(),
        ]);
    }

    public function findById(string $id): ?Customer
    {
        $model = CustomerModel::find($id);

        if (! $model) {
            return null;
        }

        return new Customer(
            $model->id,
            $model->name,
            $model->email,
            $model->phone,
            $model->document,
            $model->status ?? 'active'
        );
    }

    public function findByDocument(string $document): ?Customer
    {
        $model = CustomerModel::where('document', $document)->first();

        if (! $model) {
            return null;
        }

        return new Customer(
            $model->id,
            $model->name,
            $model->email,
            $model->phone,
            $model->document,
            $model->status ?? 'active'
        );
    }

    public function findByEmail(string $email): ?Customer
    {
        $model = CustomerModel::where('email', $email)->first();

        if (! $model) {
            return null;
        }

        return new Customer(
            $model->id,
            $model->name,
            $model->email,
            $model->phone,
            $model->document,
            $model->status ?? 'active'
        );
    }

    public function findAll(): array
    {
        $models = CustomerModel::all()->toArray();

        return $models;
    }

    public function paginate(int $page, int $perPage): array
    {
        return CustomerModel::query()->skip(($page - 1) * $perPage)->take($perPage)->get()->toArray();
    }

    public function update(Customer $customer): void
    {
        CustomerModel::where('id', $customer->id)->update([
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'document' => $customer->document,
            'updated_user_id' => Auth::id(),
        ]);
    }

    public function delete(string $id): void
    {
        CustomerModel::where('id', $id)->delete();
    }
}
