<div id="iup-footer" class="container mt-5">
	<div class="row">
		<div class="col-sm text-center text-muted">
			<strong><?php esc_html_e( "The Cloud by s3Uploads", 's3uploads' ); ?></strong>
		</div>
	</div>
	<div class="row mt-3">
		<div class="col-sm text-center text-muted">
			<a href="<?php echo esc_url( $this->api_url( '/support/?utm_source=iup_plugin&utm_medium=plugin&utm_campaign=iup_plugin&utm_content=footer&utm_term=support' ) ); ?>" class="text-muted"><?php esc_html_e( "Support", 's3uploads' ); ?></a> |
			<a href="<?php echo esc_url( $this->api_url( '/terms-of-service/?utm_source=iup_plugin&utm_medium=plugin&utm_campaign=iup_plugin&utm_content=footer&utm_term=terms' ) ); ?>" class="text-muted"><?php esc_html_e( "Terms of Service", 's3uploads' ); ?></a> |
			<a href="<?php echo esc_url( $this->api_url( '/privacy/?utm_source=iup_plugin&utm_medium=plugin&utm_campaign=iup_plugin&utm_content=footer&utm_term=privacy' ) ); ?>" class="text-muted"><?php esc_html_e( "Privacy Policy", 's3uploads' ); ?></a>
		</div>
	</div>
	<div class="row mt-3">
		<div class="col-sm text-center text-muted">
			<a href="https://twitter.com/s3uploads" class="text-muted" data-toggle="tooltip" title="<?php esc_attr_e( 'Twitter', 's3uploads' ); ?>"><span class="dashicons dashicons-twitter"></span></a>
			<a href="https://www.facebook.com/s3uploads/" class="text-muted" data-toggle="tooltip" title="<?php esc_attr_e( 'Facebook', 's3uploads' ); ?>"><span class="dashicons dashicons-facebook-alt"></span></a>
		</div>
	</div>
</div>
