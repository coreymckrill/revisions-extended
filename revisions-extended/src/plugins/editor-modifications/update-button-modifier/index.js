/**
 * External dependencies
 */
import { useEffect, useState } from 'react';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { ConfirmWindow } from '../../../components';
import { usePost, useRevision, useInterface, useTypes } from '../../../hooks';
import { POST_STATUS_SCHEDULED } from '../../../settings';
import {
	getEditUrl,
	getFormattedDate,
	getAllRevisionUrl,
} from '../../../utils';

/**
 * Module Constants
 */

const FUTURE_SUPPORT_NOTICE_ID = 'revisions-extended-future-support-notice';

const UpdateButtonModifier = () => {
	const [ typeInfo, setTypeInfo ] = useState();
	const [ newRevision, setNewRevision ] = useState();
	const { create } = useRevision();
	const { setBtnDefaults } = useInterface();
	const {
		savedPost,
		changingToScheduled,
		getEditedPostAttribute,
	} = usePost();
	const { loaded: loadedTypes, getTypeInfo } = useTypes();

	const displaySaveError = () => {
		dispatch( 'core/notices' ).createNotice(
			'error',
			__( 'Error creating update.', 'revisions-extended' )
		);
	};

	const _savePost = async () => {
		const isFutureRevision = changingToScheduled();
		const noticeDispatch = dispatch( 'core/notices' );

		// We currently don't properly support 'pending' revisions,
		// Alert user they should select a date in the future
		if ( ! isFutureRevision ) {
			noticeDispatch.createWarningNotice(
				__(
					'We currently only support updates with future publish dates. Please select a date in the future.',
					'revisions-extended'
				),
				{
					id: FUTURE_SUPPORT_NOTICE_ID,
				}
			);

			return;
		}
		// This will currently fail quietly if it doesn't exist, since it's *hopefully* temporary, low risk.
		noticeDispatch.removeNotice( FUTURE_SUPPORT_NOTICE_ID );

		// We have to refetch because the context is obliterated because this function has been associated to the html element.
		const restBase = await getTypeInfo( savedPost.type, 'rest_base' );

		if ( ! restBase ) {
			displaySaveError();
			return;
		}

		const { data, error } = await create( {
			restBase,
			postId: savedPost.id,
			date: getEditedPostAttribute( 'date' ),
			title: getEditedPostAttribute( 'title' ),
			excerpt: getEditedPostAttribute( 'excerpt' ),
			content: getEditedPostAttribute( 'content' ),
			changingToScheduled: isFutureRevision,
		} );

		if ( error ) {
			displaySaveError();
		}

		if ( data ) {
			setNewRevision( data );
		}
	};

	useEffect( () => {
		setBtnDefaults( {
			callback: async () => {
				return await _savePost();
			},
		} );
	}, [] );

	useEffect( () => {
		if ( ! loadedTypes ) return;

		const _getTypeInfo = async () => {
			const _typeInfo = await getTypeInfo( savedPost.type );

			setTypeInfo( _typeInfo );
		};

		_getTypeInfo();
	}, [ loadedTypes ] );

	if ( newRevision ) {
		return (
			<ConfirmWindow
				title="Revisions Extended"
				notice={
					<Notice status="success" isDismissible={ false }>
						{ newRevision.status === POST_STATUS_SCHEDULED ? (
							<Fragment>
								<span>
									{ ' ' }
									{ __(
										'Successfully saved your update for publish on:',
										'revisions-extended'
									) }
								</span>
								<b style={ { display: 'block' } }>
									{ getFormattedDate( newRevision.date ) }
								</b>
							</Fragment>
						) : (
							<span>
								{ __(
									'Successfully saved your update.',
									'revisions-extended'
								) }
							</span>
						) }
					</Notice>
				}
				links={ [
					{
						text: __(
							'Continue editing your update.',
							'revisions-extended'
						),
						href: getEditUrl( newRevision.id ),
					},
					{
						text: sprintf(
							// translators: %s: post type.
							__( 'Edit original %s.', 'revisions-extended' ),
							typeInfo.labels.singular_name.toLowerCase()
						),
						href: getEditUrl( savedPost.id ),
					},
					{
						text: sprintf(
							// translators: %s: post type.
							__( 'View all %s updates.', 'revisions-extended' ),
							typeInfo.labels.singular_name.toLowerCase()
						),
						href: getAllRevisionUrl( savedPost.type ),
					},
				] }
			/>
		);
	}

	return null;
};

export default UpdateButtonModifier;
