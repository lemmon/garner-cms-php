<script>
  import { page } from '$app/state';
</script>

<div class="m-auto">
  <h1 class="text-4xl font-medium tracking-tight">
    {page.status}
    {page.error.message}
  </h1>
</div>
