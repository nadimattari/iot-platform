<template>
  <section>
    <h1>Overview</h1>
    <p class="lead">Monitor and control your connected devices from one console.</p>

    <div class="card-grid">
      <RouterLink to="/dashboard/devices" class="card">
        <span class="pi pi-microchip" aria-hidden="true" />
        <h2>Devices</h2>
        <p>List devices, view online status and provisioning details.</p>
      </RouterLink>

      <RouterLink to="/dashboard/insights" class="card">
        <span class="pi pi-chart-line" aria-hidden="true" />
        <h2>Insights</h2>
        <p>Time-series summaries and bucketed charts from continuous aggregates.</p>
      </RouterLink>
    </div>
  </section>
</template>
