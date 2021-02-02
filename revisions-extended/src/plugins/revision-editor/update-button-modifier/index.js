/**
 * External dependencies
 */
import { useEffect, useState } from 'react';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { dispatch } from '@wordpress/data';
import { Notice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { ConfirmWindow } from '../../../components';
import { usePost, useRevision, useInterface } from '../../../hooks';
import { getEditUrl, getAllRevisionUrl } from '../../../utils';

/**
 * Module constants
 */
const PROP_BTN_TEXT = 'btnText';
const PROP_FN_SAVE = 'savePost';

const UpdateButtonModifier = () => {
	const [ showSuccess, setShowSuccess ] = useState( false );
	const {
		shouldIntercept,
		setBtnText,
		setSavePostFunction,
		getStashProp,
	} = useInterface();
	const { savedPost } = usePost();
	const { publish } = useRevision();

	const _savePost = async ( gutenbergProps ) => {
		if ( gutenbergProps && gutenbergProps.isAutosave ) {
			return;
		}

		const { data, error } = await publish( {
			postId: savedPost.parent,
			postType: 'post',
			revisionId: savedPost.id,
		} );

		if ( data ) {
			setShowSuccess( true );
		}

		if ( error ) {
			dispatch( 'core/notices' ).createNotice(
				'error',
				__( 'Error publishing revision.' )
			);
		}
	};

	useEffect( () => {
		let btnText = getStashProp( PROP_BTN_TEXT );
		let savePost = getStashProp( PROP_FN_SAVE );

		if ( shouldIntercept ) {
			btnText = __( 'Publish', 'revisions-extended' );
			savePost = _savePost;
		}

		if ( btnText ) {
			setBtnText( btnText );
		}

		if ( savePost ) {
			setSavePostFunction( savePost );
		}
	}, [ shouldIntercept ] );

	if ( showSuccess ) {
		return (
			<ConfirmWindow
				title="Revisions Extended"
				notice={
					<Notice status="success" isDismissible={ false }>
						Successfully published your update.
					</Notice>
				}
				links={ [
					{
						text: 'View published post.',
						href: `/?p=${ savedPost.parent }`,
					},
					{
						text: 'Reload original post.',
						href: getEditUrl( savedPost.parent ),
					},
					{
						text: `View all ${ savedPost.type } updates.`,
						href: getAllRevisionUrl( savedPost.type ),
					},
				] }
			/>
		);
	}

	return null;
};

export default UpdateButtonModifier;
