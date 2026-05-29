<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\LaravelAi;

final class EventClassMap
{
    /**
     * @return list<class-string>
     */
    public static function events(): array
    {
        return array_values(array_filter([
            'Laravel\\Ai\\Events\\PromptingAgent',
            'Laravel\\Ai\\Events\\AgentPrompted',
            'Laravel\\Ai\\Events\\StreamingAgent',
            'Laravel\\Ai\\Events\\AgentStreamed',
            'Laravel\\Ai\\Events\\InvokingTool',
            'Laravel\\Ai\\Events\\ToolInvoked',
        ], class_exists(...)));
    }
}
