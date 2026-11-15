<?php

namespace Database\Seeders;

use App\Application\Customer\DTOs\CreateCustomerDTO;
use App\Application\Customer\UseCases\CreateCustomerUseCase;
use App\Application\Vehicle\DTOs\CreateVehicleDTO;
use App\Application\Vehicle\UseCases\CreateVehicleUseCase;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class DevDataSeeder extends Seeder
{
    public function run(): void
    {
        // ------------------------------------------------------------------ //
        // 1. Usuário
        // ------------------------------------------------------------------ //
        $email = 'dev@example.com';
        $password = 'password';

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Dev User',
                'document' => '52998224725', // CPF válido
                'role' => 'atendente',
                'password' => Hash::make($password),
            ]
        );

        // Autenticar para que os use cases que chamam Auth::id() funcionem
        Auth::guard('api')->login($user);
        $token = Auth::guard('api')->tokenById($user->id);

        // ------------------------------------------------------------------ //
        // 3. Veículo
        // ------------------------------------------------------------------ //
        /** @var CreateVehicleUseCase $createVehicle */
        $createVehicle = app(CreateVehicleUseCase::class);

        $vehicle = $createVehicle->execute(new CreateVehicleDTO(
            brand: 'Toyota',
            model: 'Corolla',
            year: 2020,
            plate: 'DEV1D23',
        ));

        // ------------------------------------------------------------------ //
        // 4. Cliente
        // ------------------------------------------------------------------ //
        /** @var CreateCustomerUseCase $createCustomer */
        $createCustomer = app(CreateCustomerUseCase::class);

        $customer = $createCustomer->execute(new CreateCustomerDTO(
            name: 'Cliente Dev',
            email: 'cliente@example.com',
            phone: '11999990000',
            document: '52998224725',
        ));

        // Catálogo (serviços/peças/estoque) agora vive no execution-service:
        // rode "php artisan db:seed --class=CatalogSeeder" lá. A OS é aberta
        // pela API (POST /api/service-order), que inicia a saga.
        $this->updateBrunoFolderVars(vehicleId: $vehicle->id, customerId: $customer->id);

        // ------------------------------------------------------------------ //
        // Output
        // ------------------------------------------------------------------ //
        $this->command->newLine();
        $this->command->info('=== DevDataSeeder — dados criados ===');
        $this->command->table(
            ['Campo', 'Valor'],
            [
                ['Usuário e-mail',    $email],
                ['Usuário senha',     $password],
                ['JWT Token',         $token],
                ['Vehicle ID',        $vehicle->id],
                ['Customer ID',       $customer->id],
            ]
        );
        $this->command->newLine();
    }

    private function updateBrunoFolderVars(string $vehicleId, string $customerId): void
    {
        $filePath = base_path('bruno/TechChallengeAuth/Authenticated/folder.bru');

        if (! is_file($filePath) || ($content = file_get_contents($filePath)) === false) {
            return;
        }

        $content = preg_replace('/(\n\s*vehicleId:)[^\n]*/', "$1 {$vehicleId}", $content, 1);
        $content = preg_replace('/(\n\s*customerId:)[^\n]*/', "$1 {$customerId}", $content, 1);

        file_put_contents($filePath, $content);
    }
}
