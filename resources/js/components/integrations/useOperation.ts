import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import type { Operation } from '@/components/integrations/integrations';
import { sendJson } from '@/components/integrations/integrations';
import { onWorkspace } from '@/lib/workspaceRoute';
import integrationRoutes from '@/routes/workspace/integrations';

const ACTIVE = ['queued', 'running'];

export function operationActive(operation: Operation | null): boolean {
    return operation !== null && ACTIVE.includes(operation.state);
}

export function useOperation(
    slug: () => string,
    initial: Operation | null = null,
    onFinish?: (operation: Operation) => void,
): { operation: Ref<Operation | null>; follow: (op: Operation) => void } {
    const operation = ref<Operation | null>(initial);
    let timer: ReturnType<typeof setTimeout> | undefined;

    async function poll(): Promise<void> {
        const current = operation.value;
        if (!current || !operationActive(current)) return;
        const result = await sendJson<{ operation: Operation }>(
            'GET',
            onWorkspace(
                integrationRoutes.operations.show({
                    workspace: slug(),
                    integration: current.integrationId,
                    operation: current.id,
                }),
            ).url,
        );
        if (result.ok && operation.value?.id === current.id) {
            operation.value = result.data.operation;
            if (!operationActive(result.data.operation)) {
                onFinish?.(result.data.operation);
            }
        }
        schedule();
    }

    function schedule(): void {
        clearTimeout(timer);
        if (operationActive(operation.value)) {
            timer = setTimeout(poll, 3000);
        }
    }

    function follow(op: Operation): void {
        operation.value = op;
        schedule();
    }

    watch(
        () => operation.value?.id,
        () => schedule(),
        { immediate: true },
    );

    onBeforeUnmount(() => clearTimeout(timer));

    return { operation, follow };
}
