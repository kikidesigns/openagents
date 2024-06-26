<?php

namespace App\Models;

use App\AI\MistralAIGateway;
use App\Services\SemanticRouter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Run extends Model
{
    use HasFactory;

    protected $fillable = ['agent_id', 'flow_id', 'thread_id', 'input'];

    // belongs to an Agent
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    // belongs to a Flow
    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    // belongs to a Thread
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /**
     * Triggers the execution of the associated flow.
     *
     * @param  callable  $streamingFunction  A callback function for streaming the output.
     * @return string The aggregated result of the flow execution.
     */
    public function trigger(callable $streamingFunction): string
    {
        // Ensure the flow is loaded along with its nodes
        $this->load('flow.nodes');

        $input = $this->input; // Assume this run model has an 'input' attribute.
        $output = '';

        // First see if we should override node with a semantic route
        // Vectorize the input
        $gateway = new MistralAIGateway();
        $vectorizedInput = $gateway->embed($input);

        // If input contains the phrase "make an image of", set route manually to "make_an_image_of"
        if (str_contains($input, 'make an image of')) {
            $route = 'make_an_image_of';
        } else {
            $router = new SemanticRouter();
            $route = $router->route($vectorizedInput);
        }

        switch ($route) {
            case 'zipcode':
                $plugin = Plugin::where('name', 'World Zipcode Finder')->first();
                if (! $plugin) {
                    $plugin = Plugin::create([
                        'name' => 'World Zipcode Finder',
                        'description' => 'Finds the location of a zipcode',
                        'wasm_url' => 'https://github.com/OpenAgentsInc/plugin-world-zipcode-finder/releases/download/v0.0.1/plugin-world-zipcode-finder.wasm',
                    ]);
                }

                // If route is zipcode, provide a custom message
                $flow = Flow::where('name', 'World Zipcode Finder')->first();
                if (! $flow) {
                    $flow = Flow::create([
                        'name' => 'World Zipcode Finder',
                    ]);
                    $flow->nodes()->create([
                        'name' => 'World Zipcode Finder',
                        'description' => 'Finds the location of a zipcode',
                        'type' => 'plugin',
                        'config' => json_encode([
                            'plugin_id' => $plugin->id,
                        ]),
                    ]);
                }
                break;

            case 'make_an_image_of':
                $flow = Flow::where('name', 'Image Generator')->first();
                if (! $flow) {
                    $flow = Flow::create([
                        'name' => 'Image Generator',
                    ]);
                    $flow->nodes()->create([
                        'name' => 'Image Generator',
                        'description' => 'Generates an image via the Stability API',
                        'type' => 'stability_text_to_image',
                    ]);
                }
                break;

            case 'bitcoin_price':
            case 'finance':
                // If route is finance, trigger the Finnhub flow
                $flow = Flow::where('name', 'Financial Analysis')->first();
                if (! $flow) {
                    $flow = Flow::create([
                        'name' => 'Financial Analysis',
                    ]);
                    $flow->nodes()->create([
                        'name' => 'Finnhub Function Call',
                        'description' => 'Passes input to Mistral AI Gateway for Finnhub function call',
                        'type' => 'finnhub_function_call',
                        'config' => json_encode([
                            'gateway' => 'mistral',
                            'model' => 'mistral-large-latest',
                        ]),
                    ]);
                }
                break;

            case 'shitcoins':
            case 'bitcoin_cash':
            case 'bsv':
                // If route is shitcoins, provide a custom message
                return 'Shitcoins are not supported. Study Bitcoin.';

            case 'craig_wright_satoshi':
                // If route is Craig Wright, provide a custom message
                return 'We are all Satoshi, except Craig Wright.';

            case 'bitcoin':
            default:
                // For any other route, use the existing flow
                $flow = $this->flow;
                break;
        }

        // Execute each node in sequence (assuming nodes are already sorted by their execution order)
        foreach ($flow->nodes as $node) {

            // Call the node's trigger method and pass the current input
            $nodeOutput = $node->trigger([
                'agent' => $this->agent,
                'flow' => $flow,
                'thread' => $this->thread,
                'input' => $input,
                'streamingFunction' => $streamingFunction,
            ]);

            // Set the output of the current node as the input for the next node
            $input = $nodeOutput;

            // Append the output to the overall run output (this can be adjusted based on your needs)
            $output .= $nodeOutput;
        }

        // Return the final aggregated output
        return $output;
    }
}
