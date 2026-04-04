<script>
  let { class: classname = '', children, ...props } = $props();
</script>

<form class={['space-y-6', classname]} {...props}>
  {@render children()}
</form>
