<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';

// The pricing section's slow cobalt sky: soft drifting cloud and film grain,
// drawn on the GPU at half resolution. Without WebGL, and until the first
// frame, a gradient in the same blues stands in.

const vertex = `
attribute vec2 a;
void main() { gl_Position = vec4(a, 0.0, 1.0); }
`;

const fragment = `
precision highp float;
uniform vec2 u_res;
uniform float u_time;
// 0: the pricing section's cobalt sky. 1: dark cloud, for the dark dashboard.
uniform float u_tone;

float hash(vec2 p) {
    p = fract(p * vec2(123.34, 456.21));
    p += dot(p, p + 45.32);
    return fract(p.x * p.y);
}

float noise(vec2 p) {
    vec2 i = floor(p);
    vec2 f = fract(p);
    vec2 u = f * f * (3.0 - 2.0 * f);
    return mix(
        mix(hash(i), hash(i + vec2(1.0, 0.0)), u.x),
        mix(hash(i + vec2(0.0, 1.0)), hash(i + vec2(1.0, 1.0)), u.x),
        u.y
    );
}

float fbm(vec2 p) {
    float v = 0.0;
    float a = 0.5;
    mat2 m = mat2(1.6, 1.2, -1.2, 1.6);
    for (int i = 0; i < 6; i++) {
        v += a * noise(p);
        p = m * p;
        a *= 0.5;
    }
    return v;
}

void main() {
    vec2 uv = gl_FragCoord.xy / u_res;
    float aspect = u_res.x / u_res.y;
    vec2 p = vec2(uv.x * aspect, uv.y) * 1.8;
    float t = u_time * 0.018;

    vec2 q = vec2(fbm(p + vec2(t, 0.0)), fbm(p + vec2(5.2, 1.3) - vec2(0.0, t * 0.6)));
    float c = fbm(p + 2.0 * q + vec2(t * 0.5, 0.0));

    // Deep navy at the top fading into cobalt and a lighter azure low down.
    vec3 deep = mix(vec3(0.03, 0.10, 0.40), vec3(0.05, 0.055, 0.075), u_tone);
    vec3 mid = mix(vec3(0.07, 0.29, 0.72), vec3(0.09, 0.11, 0.165), u_tone);
    vec3 light = mix(vec3(0.32, 0.56, 0.92), vec3(0.18, 0.21, 0.31), u_tone);
    float g = clamp((1.0 - uv.y) * 0.75 + abs(uv.x - 0.5) * 0.35, 0.0, 1.0);
    vec3 sky = mix(deep, mid, smoothstep(0.0, 0.55, g));
    sky = mix(sky, light, smoothstep(0.6, 1.0, g));

    // Cloud gathers low and towards the sides, leaving the top centre clear for text.
    float bias = smoothstep(0.8, 0.05, uv.y) * 0.5 + abs(uv.x - 0.5) * 0.45 - 0.1;
    float cloud = smoothstep(0.46, 0.8, c + bias * 0.6);
    vec3 cloudCol = mix(
        mix(vec3(0.55, 0.68, 0.93), vec3(0.25, 0.285, 0.39), u_tone),
        mix(vec3(0.97, 0.98, 1.0), vec3(0.56, 0.60, 0.72), u_tone),
        smoothstep(0.45, 0.95, c)
    );
    vec3 col = mix(sky, cloudCol, cloud * mix(0.92, 0.85, u_tone));

    float grain = hash(gl_FragCoord.xy + fract(u_time * 0.37) * 113.0) - 0.5;
    col += grain * 0.04;

    gl_FragColor = vec4(col, 1.0);
}
`;

const props = defineProps<{ tone?: 'cobalt' | 'dark' }>();

const canvas = useTemplateRef<HTMLCanvasElement>('canvas');
const mode = ref<'pending' | 'ready' | 'failed'>('pending');
let stop: (() => void) | null = null;

function compile(gl: WebGLRenderingContext, type: number, source: string) {
    const shader = gl.createShader(type);
    if (!shader) return null;
    gl.shaderSource(shader, source);
    gl.compileShader(shader);

    return gl.getShaderParameter(shader, gl.COMPILE_STATUS) ? shader : null;
}

onMounted(() => {
    const el = canvas.value;
    const gl = el?.getContext('webgl', {
        antialias: false,
        alpha: false,
        powerPreference: 'low-power',
    });
    if (!el || !gl) {
        mode.value = 'failed';
        return;
    }
    const vs = compile(gl, gl.VERTEX_SHADER, vertex);
    const fs = compile(gl, gl.FRAGMENT_SHADER, fragment);
    const program = gl.createProgram();
    if (!vs || !fs || !program) {
        mode.value = 'failed';
        return;
    }
    gl.attachShader(program, vs);
    gl.attachShader(program, fs);
    gl.linkProgram(program);
    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
        mode.value = 'failed';
        return;
    }
    gl.useProgram(program);

    gl.bindBuffer(gl.ARRAY_BUFFER, gl.createBuffer());
    gl.bufferData(
        gl.ARRAY_BUFFER,
        new Float32Array([-1, -1, 3, -1, -1, 3]),
        gl.STATIC_DRAW,
    );
    const a = gl.getAttribLocation(program, 'a');
    gl.enableVertexAttribArray(a);
    gl.vertexAttribPointer(a, 2, gl.FLOAT, false, 0, 0);
    const res = gl.getUniformLocation(program, 'u_res');
    const time = gl.getUniformLocation(program, 'u_time');
    const toneAt = gl.getUniformLocation(program, 'u_tone');

    const still = window.matchMedia('(prefers-reduced-motion: reduce)');
    const scale = Math.min(window.devicePixelRatio || 1, 2) * 0.5;
    const start = performance.now() - 40_000;
    let frame = 0;
    let visible = true;

    const draw = (now: number) => {
        gl.uniform2f(res, el.width, el.height);
        gl.uniform1f(time, still.matches ? 40 : (now - start) / 1000);
        gl.uniform1f(toneAt, props.tone === 'dark' ? 1 : 0);
        gl.drawArrays(gl.TRIANGLES, 0, 3);
    };
    const resize = () => {
        const width = Math.max(1, Math.round(el.clientWidth * scale));
        const height = Math.max(1, Math.round(el.clientHeight * scale));
        if (el.width !== width || el.height !== height) {
            el.width = width;
            el.height = height;
            gl.viewport(0, 0, width, height);
        }
        draw(performance.now());
    };
    const loop = (now: number) => {
        frame = 0;
        if (!visible || document.hidden || still.matches) return;
        draw(now);
        frame = requestAnimationFrame(loop);
    };
    const wake = () => {
        if (!frame && visible && !document.hidden && !still.matches) {
            frame = requestAnimationFrame(loop);
        }
    };

    resize();
    mode.value = 'ready';
    wake();

    const sized = new ResizeObserver(resize);
    sized.observe(el);
    const seen = new IntersectionObserver(([entry]) => {
        visible = entry.isIntersecting;
        wake();
    });
    seen.observe(el);
    document.addEventListener('visibilitychange', wake);
    still.addEventListener('change', resize);

    stop = () => {
        cancelAnimationFrame(frame);
        sized.disconnect();
        seen.disconnect();
        document.removeEventListener('visibilitychange', wake);
        still.removeEventListener('change', resize);
        gl.getExtension('WEBGL_lose_context')?.loseContext();
    };
});

onBeforeUnmount(() => stop?.());
</script>

<template>
    <div
        aria-hidden="true"
        class="pointer-events-none"
        :class="
            tone === 'dark'
                ? 'bg-[radial-gradient(120%_80%_at_50%_100%,#1c2132_0%,#10131c_45%,#090a0e_100%)]'
                : 'bg-[radial-gradient(120%_80%_at_50%_100%,#5a8fe6_0%,#1f5cc4_38%,#0c2f93_70%,#071a66_100%)]'
        "
    >
        <canvas
            ref="canvas"
            class="absolute inset-0 size-full transition-opacity duration-500"
            :class="mode === 'ready' ? 'opacity-100' : 'opacity-0'"
        />
    </div>
</template>
