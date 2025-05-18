<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class VerifySalesNagios extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'verify:sales-nagios';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command that runs a script which, for each store (both app and non-app), checks if there is an incident 
                              based on a specific criterion. If an incident is found, it returns a valid string for Nagios.
                              
                              Comando que ejecuta un script donde para cada store (tanto app como no), observa si posee incidencia o no
                              en base a un criterio específico. Retorna en caso de incidencia un string válido para nagios';

    /**
     * Execute the console command.
     * 
     * Explicación del comando:
     *  Leer los datos de la tabla datos.
     * 
     *  Comparar cada combinación store_id, app, hora con el valor de store_id = 1.
     * 
     *  Validar si pedido_minimos <= (pedido_minimos de store_id 1)/2.
     * 
     *  Obtener el nombre del store desde store_website por store_id.
     * 
     *  Construir la cadena de salida como: 
     *     "store {nombre}, app={valor_app}, valor = {valor}"
     * 
     *  Al final, imprimir: WARNING - Incidencia ventas horas | store pt, app=0, valor = 300 | ...
     *  Y retornar código de salida 1 (WARNING).
     * 
     *  Otros codigos de salida:
     *  El plugin debe terminar con un código de salida específico que Nagios interpreta como el estado del servicio.
     * 
     *  	Código  Estado    Significado
     *      0       OK        Todo está bien
     *      1       WARNING   Hay una advertencia 
     *      2       CRITICAL  Hay un problema grave 
     *      3       UNKNOWN   Estado desconocido o error
     * 
     *  handle() retorna el código de salida que Nagios interpreta
     */
    public function handle()
    {
        $this->info('== Start to execute command verify:sales-nagios =='); //Comienzo de ejecución de comando verify:sales-nagios

        // 1. Cargar datos del CSV
        $this->info('getCsvPath execute'); //Ejecución de getCsvPath
        $csvPath = $this->getCsvPath();
        if (!$this->fileExists($csvPath)) {
            $this->error("CSV file not found at: {$csvPath}"); //El archivo CSV no se encuentra en $csvPath
            return 2; //CRITICAL - Hay un problema grave
        }
        $this->info('Path of Excell file: ' . $csvPath);

        $this->info('loadCsvData execute'); //Ejecución de loadCsvData
        $data = collect($this->loadCsvData($csvPath));
        if ($data->isEmpty()) {
            $this->info("OK - CSV has no data.");
            return 0; //OK - Todo está bien
        }
        $this->info('Data from excell charged, total elements: ' . $data->count()); //Datos cargados desde el csv excell, cantidad de elementos: count

        // 2. Cargar equivalencias de stores (desde tabla importada con SQL)
        $this->info('storeNames execute'); //Ejecución de get datos de la BD (create_store_website_table)
        $storeNames = $this->loadStoreNames();

        $this->previewStoreNames($storeNames);
        $this->previewFirstElements($data);

        // 3. Agrupar datos base (store_id = 1) por hora y app
        $base = $this->buildBaseDataStore1($data);
        $this->previewBaseDataStore1($base);

        // 4. Comparar y detectar incidencias
        $incidents = $this->detectIncidents($data, $base, $storeNames);

        // 5. Construir salida Nagios   
        $resultOutputNagios = $this->outputNagiosResult($incidents);
        return $resultOutputNagios; //WARNING - Hay una advertencia
    }


    /*
        Private methods
    */

    /*
        Método que carga los datos del CSV.
        Se obtiene datos.csv desde \storage\app

        @return string
    */
    private function getCsvPath(): string
    {
        return storage_path('app\datos.csv');
    }

    /*
        Método que verifica si el archivo CSV existe y si el archivo puede leerse en cuanto a permisos.

        Params:
        @$path String
        @return bool
    */
    private function fileExists(string $path): bool
    {
        return file_exists($path) && is_readable($path);
    }

    /*
        Método que lee el archivo datos.csv desde storage/app/ y lo convierte a un arreglo asociativo.
        fgetcsv sabe que el delimitador es un ;

        Params:
        @$path string
        @return array
    */
    private function loadCsvData(string $path): array
    {
        $csvData = [];
        
        if (!file_exists($path) || !is_readable($path)) {
            return $csvData;
        }

        if (($handle = fopen($path, 'r')) !== false) {
            $header = null;
            while (($row = fgetcsv($handle, 1000, ';')) !== false) {
                if (!$header) {
                    $header = $row;
                } else {
                    $csvData[] = array_combine($header, $row);
                }
            }
            fclose($handle);
        }

        return $csvData;
    }
    
    /*
        Método que lee los datos que posee en la tabla store_website de la BD configurada en DB_DATABASE

        Params:
        @return Collection
    */
    private function loadStoreNames(): Collection
    {
        return DB::table('store_website')->get()->keyBy('website_id');
    }

    /*
        Método que muestra los datos que contiene la tabla store_website cargada en $storeNames 

        Params:
        @$storeames Collection
    */
    private function previewStoreNames(Collection $storeNames): void
    {
        $this->info('=== Store names loaded ===');
        foreach ($storeNames as $store) {
            $this->line("website_id: {$store->website_id} | code: {$store->code} | name: {$store->name}");
        }

        $this->line('===============================================');
    }

    /*
        Método que muestra los primeros elementos del CSV

        Params:
        @$date Collection
    */
    private function previewFirstElements(Collection $data): void
    {
        $this->info('=== First elements from CSV ===');
        foreach ($data->take(5) as $i => $item) {
            $this->line("#{$i}: " . json_encode($item));
        }

        $this->line('...');
        $this->line('===============================================');
    }

    /*
        Método que agrupa los datos base (store_id = 1) por hora y app.
        Ejemplo: Para '1-0 => 1593' implica: hora 1, app 0 y pedidos_minimos 1593

        Params:
        @$data Collection
        @return Collection
    */
    private function buildBaseDataStore1(Collection $data): Collection
    {
        return $data->where('store_id', 1)
                    ->groupBy(fn ($item) => $item['hora'] . '-' . $item['app'])
                    ->mapWithKeys(fn ($group, $key) => [$key => floatval($group->first()['pedidos_minimos'])]);
    }

    /*
        Método que muestra los datos de referencia de la store_id = 1

        Params:
        @$base Collection
    */
    private function previewBaseDataStore1(Collection $base): void
    {
        $this->line("=== Base reference (store_id = 1) ===");
        foreach ($base as $key => $valor) {
            $this->line("{$key} => {$valor}");
        }

        $this->line("==========================");
    }

    /*
        Método que retorna las incidecias detectadas.
        Compara y detecta las incidencias en base a los criterios:
          Por cada registro de $data, obtenemos el store_id, si es '1', nos quedamos con el valor de pedidos_minimos. 
          Para cada fila distinta de store_id = 1, compara su pedidos_minimos con la mitad del valor base correspondiente (valor base = hora-app).

          Si es app o no, se informa al momento de detectar una incidencia: app=0 o app=1.

          Habrá una incidencia por cada tienda cuya venta está a la mitad o menos del valor base para su combinación hora-app.
          En otras palabras detecta incidencias de ventas por hora, tienda y si es app o no, comparado con un valor base de referencia.

        Params:
        @$data Collection: Datos (listado) obtenidos del CSV
        @$base Collection: Datos base agrupados por hora y app pertenecientes a store_id = 1.
        @$storeNames
        @return array
    */
    private function detectIncidents(Collection $data, Collection $base, Collection $storeNames): array
    {
        $incidents = [];

        foreach ($data as $row) {
            $key = $row['hora'] . '-' . $row['app'];
            $storeId = intval($row['store_id']);

            if ($storeId === 1) {
                continue; // Saltar el store base. Salta a la siguiente iteración del foreach si $storeId === 1. Compara los distintos a si mismo
            }

            if (!isset($base[$key])) {
                continue; // No hay base para comparar
            }

            $value = round(floatval($row['pedidos_minimos'])); //Redondeamos porque son pedidos por unidad
            $baseValue = $base[$key];

            //Verify issue
            if ($value <= ($baseValue / 2)) {
                $nameStoreFromTable = $storeNames[$storeId]->code ?? "store_id {$storeId}"; //Retornará Si $storeId = 3 --> "it", si no localiza el code: "store_id 3".
                $incidents[] = "store {$nameStoreFromTable}, app={$row['app']}, valor = {$value}";
            }  
        }
    
        return $incidents;
    }

    /*
        Método que muestra el Warning de nagios en caso de haber incidencias y retorna código = 1, Estado = WARNING, Significado = Hay una advertencia.
        Es decir, tendremos un WARNING resultando; en el cual habrá una línea por cada tienda cuya venta está a la mitad o menos del valor base para su 
        combinación hora-app.    

        Params:
        @$path incidents
        @return int
    */
    private function outputNagiosResult(array $incidents): int
    {
        if (!empty($incidents)) {
            $mensaje = 'WARNING - Incidencia ventas horas | ' . implode(' | ', $incidents);
            $this->warn($mensaje);
            return 1;
        } else {
            $this->info('OK - sin incidencias detectadas');
            return 0;
        }
    }

}
