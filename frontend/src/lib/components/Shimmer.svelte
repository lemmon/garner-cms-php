<script>
  import { twMerge } from 'tailwind-merge';

  let { class: classname, children, ...props } = $props();
</script>

<div
  class={twMerge(
    'relative min-w-16 overflow-clip',
    'bg-current/5 before:bg-current/10',
    classname
  )}
  role="status"
  aria-busy="true"
  {...props}
>
  <span class="invisible"
    >{#if children}{@render children()}{:else}&hellip;{/if}</span
  >
</div>

<style>
  div {
    position: relative;

    &::before {
      content: '';
      position: absolute;
      inset: 0;
      animation: 3s ease-in-out skeleton infinite;
    }
  }

  @keyframes skeleton {
    0% {
      opacity: 0.3;
      transform: scaleX(0);
      transform-origin: left;
    }

    20% {
      opacity: 1;
      transform: scaleX(1);
      transform-origin: left;
    }

    28% {
      transform: scaleX(1);
      transform-origin: right;
    }

    51% {
      transform: scaleX(0);
      transform-origin: right;
    }

    58% {
      transform: scaleX(0);
      transform-origin: right;
    }

    82% {
      transform: scaleX(1);
      transform-origin: right;
    }

    83% {
      transform: scaleX(1);
      transform-origin: left;
    }

    96% {
      transform: scaleX(0);
      transform-origin: left;
    }

    to {
      opacity: 0.3;
      transform: scaleX(0);
      transform-origin: left;
    }
  }
</style>
