<?php

namespace App\lib\ai;

/**
 * GIA tool contract (BRD §10.4 "Tool registry — minimum contracts").
 *
 * A GIA tool is a governed, typed capability the agent may invoke. Phase A ships
 * READ-ONLY tools only (no state-changing / money actions). The agent runtime
 * (giaAgent) never lets the model execute a tool whose input fails schema
 * validation, and never promotes a model's free-form text into a tool call
 * (BRD §10.3 planner/executor separation, §10.6 tool discipline).
 *
 * This mirrors the existing aiProviderInterface style (namespace App\lib\ai).
 */
interface giaToolInterface
{
    /** Stable machine name sent to the model (e.g. "search_trip"). [a-z0-9_]. */
    public function name(): string;

    /** One-line capability description the model reads to decide when to call it. */
    public function description(): string;

    /**
     * JSON Schema object describing this tool's input:
     *   ['type' => 'object', 'properties' => [...], 'required' => [...]]
     * The runtime passes this verbatim into each provider's tool definition
     * (Claude input_schema / OpenAI function.parameters / Gemini parameters).
     */
    public function inputSchema(): array;

    /**
     * Risk class per BRD §10.5 (R0..R5). Phase A tools MUST be R0/R1 (read-only).
     * The runtime refuses to execute anything above the configured ceiling.
     */
    public function riskClass(): string;

    /**
     * Execute the tool with already-schema-validated input.
     *
     * @param array $input  Validated arguments (shape per inputSchema()).
     * @param array $context  Runtime context: ['db'=>$db, 'account_id'=>?, 'session'=>...].
     * @return array{ok:bool, data?:array, message?:string, error_code?:string}
     *         The runtime serializes this back to the model as the tool_result.
     */
    public function run(array $input, array $context): array;
}
