<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ConvertTcpdfFont extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tcpdf:convert-font {--font= : Specific font file to convert}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert TTF fonts to TCPDF format with interactive font selection';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('TCPDF Font Converter');
        $this->line('===================');
        
        $fontsPath = public_path('fonts');

        // Check if fonts directory exists
        if (!File::exists($fontsPath)) {
            $this->error("Fonts directory not found: {$fontsPath}");
            return 1;
        }

        // Get all TTF font files
        $fontFiles = File::glob($fontsPath . '/*.ttf');

        if (empty($fontFiles)) {
            $this->error('No TTF font files found in ' . $fontsPath);
            return 1;
        }

        // If specific font is provided via option
        if ($font = $this->option('font')) {
            $fontPath = $fontsPath . '/' . $font;
            if (!File::exists($fontPath)) {
                $this->error("Font file not found: {$fontPath}");
                return 1;
            }
            return $this->convertFont($fontPath);
        }

        // Display available fonts
        $this->info('Available fonts:');
        $this->line('');

        $fontOptions = [];
        foreach ($fontFiles as $index => $fontFile) {
            $fontName = basename($fontFile);
            $fontOptions[] = $fontName;
            $this->line(($index + 1) . '. ' . $fontName);
        }
        $this->line('');

        // Get user selection
        $choice = $this->ask('Enter the number of the font you want to convert (or press Enter to convert all)');

        if (empty($choice)) {
            // Convert all fonts
            $this->info('Converting all fonts...');
            $success = true;
            foreach ($fontFiles as $fontFile) {
                if (!$this->convertFont($fontFile)) {
                    $success = false;
                }
            }
            return $success ? 0 : 1;
        }

        // Convert selected font
        $fontIndex = (int) $choice - 1;
        if ($fontIndex < 0 || $fontIndex >= count($fontFiles)) {
            $this->error('Invalid selection');
            return 1;
        }

        $selectedFont = $fontFiles[$fontIndex];
        return $this->convertFont($selectedFont);
    }

    /**
     * Convert a single font file
     */
    private function convertFont(string $fontPath): bool
    {
        $fontName = basename($fontPath);
        $this->info("Converting font: {$fontName}");

        // Get the TCPDF addfont script path
        $tcpdfScript = base_path('vendor/tecnickcom/tcpdf/tools/tcpdf_addfont.php');

        if (!File::exists($tcpdfScript)) {
            $this->error("TCPDF addfont script not found: {$tcpdfScript}");
            return false;
        }

        // Get the output path (TCPDF fonts directory)
        $outputPath = base_path('vendor/tecnickcom/tcpdf/fonts');

        if (!File::exists($outputPath)) {
            $this->error("TCPDF fonts directory not found: {$outputPath}");
            return false;
        }

        // Build the command
        $command = sprintf(
            'php "%s" -b -t TrueTypeUnicode -i "%s" -o "%s"',
            $tcpdfScript,
            $fontPath,
            $outputPath
        );

        $this->line("Running command: {$command}");
        $this->line('');

        // Execute the command
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);

        // Display output
        foreach ($output as $line) {
            if (strpos($line, 'ERROR') !== false || strpos($line, '---') !== false) {
                $this->error($line);
            } elseif (strpos($line, '+++') !== false || strpos($line, '>>>') !== false) {
                $this->info($line);
            } else {
                $this->line($line);
            }
        }

        if ($returnCode === 0) {
            $this->info("✅ Font '{$fontName}' converted successfully!");
            return true;
        } else {
            $this->error("❌ Failed to convert font '{$fontName}'");
            return false;
        }
    }
}

