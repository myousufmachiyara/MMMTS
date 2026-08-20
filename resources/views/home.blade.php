@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
	<div>
		<h2 class="text-dark"><strong id="currentDate"></strong></h2>
	</div>	
	{{-- <div class="row">
		<div class="col-12 col-md-3 mb-2">	
			<section class="card card-featured-left card-featured-success">
				<div class="card-body icon-container data-container">
					<h3 class="amount text-dark"><strong>Today's Sales</strong></h3>	
					<h2 class="amount m-0 text-success">
						<strong data-value="">0</strong>
						<span class="title text-end text-dark h6"> PKR</span>
					</h2>	
					<div class="summary-footer">
						<a class="text-success text-uppercase" href="#">View Details</a>
					</div>
				</div>
			</section>
		</div>
		<div class="col-12 col-md-3 mb-2">	
			<section class="card card-featured-left card-featured-primary">
				<div class="card-body icon-container data-container">
					<h3 class="amount text-dark"><strong>Month's Sales</strong></h3>	
					<h2 class="amount m-0 text-primary">
						<strong data-value="">0</strong>
						<span class="title text-end text-dark h6"> PKR</span>
					</h2>	
					<div class="summary-footer">
						<a class="text-primary text-uppercase" href="#">View Details</a>
					</div>
				</div>
			</section>
		</div>
		<div class="col-12 col-md-3 mb-2">	
			<section class="card card-featured-left card-featured-danger">
				<div class="card-body icon-container data-container">
					<h3 class="amount text-dark"><strong>Receivables</strong></h3>	
					<h2 class="amount m-0 text-danger">
						<strong data-value="">0</strong>
						<span class="title text-end text-dark h6"> PKR</span>
					</h2>	
					<div class="summary-footer">
						<a class="text-danger text-uppercase" href="#">View Details</a>
					</div>
				</div>
			</section>
		</div>
		<div class="col-12 col-md-3 mb-2">	
			<section class="card card-featured-left card-featured-tertiary">
				<div class="card-body icon-container data-container">
					<h3 class="amount text-dark"><strong>Payables</strong></h3>	
					<h2 class="amount m-0 text-tertiary">
						<strong data-value="">0</strong>
						<span class="title text-end text-dark h6"> PKR</span>
					</h2>	
					<div class="summary-footer">
						<a class="text-tertiary text-uppercase" href="#">View Details</a>
					</div>
				</div>
			</section>
		</div>
		<div class="col-12 col-md-3 mb-2">	
			<section class="card card-featured-left card-featured-danger">
				<div class="card-body icon-container data-container">
					<h3 class="amount text-dark"><strong>Sale Returns</strong></h3>	
					<h2 class="amount m-0 text-danger">
						<strong data-value="">0</strong>
						<span class="title text-end text-dark h6"> PKR</span>
					</h2>	
					<div class="summary-footer">
						<a class="text-danger text-uppercase" href="#">View Details</a>
					</div>
				</div>
			</section>
		</div>
	</div> --}}
	<!-- <div class="row">
		<div class="col-12 col-md-6 mb-3 d-flex">
			<section class="card flex-fill">
				<header class="card-header">
					<div class="card-actions">
						<a href="#" class="card-action card-action-toggle" data-card-toggle></a>
					</div>
					<h2 class="card-title">Pending Production</h2>
				</header>
				<div class="card-body scrollable-div">
					<table class="table table-responsive-md table-striped mb-0">
						<thead class="sticky-tbl-header">
							<tr>
								<th>Order#</th>
								<th>Date</th>
								<th>Vendor</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody id="PendingProduction" class="table-body-scroll">
						</tbody>
					</table>
				</div>
			</section>
		</div>
	</div> -->
	<!-- <div class="tabs mt-3">
		<ul class="nav nav-tabs">
			<li class="nav-item">
				<a class="nav-link nav-link-dashboard-tab" data-bs-target="#PRODUCTS" href="#PENDING_INVOICES" data-bs-toggle="tab">Products</a>
			</li>
		</ul>
		<div class="tab-content">
			<div id="PRODUCTS" class="tab-pane">
				<div class="row">
					<div class="col-12 col-md-6 mb-3 d-flex">
						<section class="card flex-fill">
							<header class="card-header">
								<div class="card-actions">
									<a href="#" class="card-action card-action-toggle" data-card-toggle></a>
								</div>
								<h2 class="card-title">Sale 2 Not Final</h2>
							</header>
							<div class="card-body scrollable-div">
								<table class="table table-responsive-md table-striped mb-0">
									<thead class="sticky-tbl-header">
										<tr>
											<th>Invoice#</th>
											<th class="text-center">Date</th>
											<th>Pur Inv#</th>
											<th>Account Name</th>
											<th>Name Of Person</th>
											<th>Remarks</th>
										</tr>
									</thead>
									<tbody id="Sale2NotTable" class="table-body-scroll">
									</tbody>
								</table>
							</div>
						</section>
					</div>
				</div>
			</div>
		</div>
	</div> -->
    <script>

		$(document).ready(function() {
			// Get current date and day
			const now = new Date();
			const day = getDaySuffix(now.getDate());
			const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
			const currentDate = now.toLocaleDateString(undefined, options);

			// Format the date as "Thursday, 5th December 2024"
			const formattedDate = `${now.toLocaleString('en-GB', { weekday: 'long' })}, ${day} ${now.toLocaleString('en-GB', { month: 'long' })} ${now.getFullYear()}`;

			// Update UI
			document.getElementById('currentDate').innerText = formattedDate;
		});	

        function getDaySuffix(day) {
			if (day >= 11 && day <= 13) {
			return day + 'th';
			}
			switch (day % 10) {
			case 1: return day + 'st';
			case 2: return day + 'nd';
			case 3: return day + 'rd';
			default: return day + 'th';
			}
		}
    </script>
@endsection