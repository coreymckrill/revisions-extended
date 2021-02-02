/**
 * External dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import RevisionIndicator from './revision-indicator';
import TrashModifier from './trash-modifier';
import PluginPostStatusInfo from './plugin-post-status-info';
import { pluginNamespace, getEditUrl } from '../../utils';

import { InterfaceProvider, usePost } from '../../hooks';

const PluginWrapper = () => {
	const { isRevision, savedPost } = usePost();

	// If we don't support the revision status, leave to the parent.
	if ( ! isRevision ) {
		window.location.href = getEditUrl( savedPost.parent );
	}
	return (
		<InterfaceProvider>
			<RevisionIndicator />
			<TrashModifier />
			<PluginPostStatusInfo />
		</InterfaceProvider>
	);
};

registerPlugin( `${ pluginNamespace }-plugin-wrapper`, {
	render: PluginWrapper,
} );
